<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Tests\Support\RecordingProvider;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-v3, item B — the "incidental mention" case the old cascade's
 * docblock defended, now that `DatasetSeeder::seeds()` merges every tier
 * instead of cascading through them (defect 1's fix).
 *
 * `PromptScopeGateTest`'s original wording — "total revenue from the gizmo
 * line, excluding cancelled ORDERS" — names "gizmo" (routes to a products
 * dataset) AND contains an orders dataset's own alias ("orders") in the same
 * sentence. Under the old cascade, the routing match suppressed the alias
 * match, which is also exactly the mechanism that caused defect 1 (a
 * question NAMING a dataset outright had the mention dropped the same way).
 * I10's permitted-signal list gives no way to tell "orders" naming a real
 * second topic apart from "orders" used incidentally — both are a bare
 * alias match in the question text — so §0 resolves the tie: a scope too
 * NARROW risks a silent wrong number with no way back; a scope too WIDE
 * risks, at worst, a bigger prompt or a loud budget refusal. This test
 * pins what "sanely" means for the WIDE side of that trade: merging does
 * not itself turn into a silent wrong number, because the dataset it widens
 * scope to include is rendered in FULL (R1) — required_filter and all — not
 * hidden.
 *
 * Fixture: `tests/Stubs/incidental-mention-schemas`, deliberately DIFFERENT
 * from `scope-gate-schemas`. That fixture's two datasets share no foreign
 * key anywhere, so `hasLinkedSchemas()` is false registry-wide and
 * `QueryOrchestrator::processWithSqlGeneration()` always takes the
 * single-dataset auto-mode prompt for it regardless of `$scope` — the
 * separately-filed "auto-mode fallback... on an unlinked schema" issue, out
 * of scope for NQ-001-v3. `im_orders` is FK-linked to `im_customers` so this
 * registry IS linked, which routes prompt-building through
 * `buildMultiDatasetPrompt()` — the path that actually renders `$scope`.
 *
 * Rows, hand-checkable: revenue 100 (paid) + 200 (paid) + 50 (cancelled) —
 * a correctly scoped, correctly filtered answer is 100 + 200 = 300.
 */
class PromptScopeIncidentalMentionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/incidental-mention-schemas');
        $app['config']->set('naturalquery.query_mode', 'sql_generation');
        $app['config']->set('naturalquery.cache.enabled', false);

        // Generous on purpose (C1 turned on, no SIZE pressure) — this test is
        // about SCOPE (relevance) per R3, never about budget.
        $app['config']->set('naturalquery.prompts.max_chars', 50000);

        // Exactly the collision that used to be suppressed: "gizmo" routes
        // to im_products, and the question below also contains im_orders'
        // own alias, "orders".
        $app['config']->set('naturalquery.query_routing', ['gizmo' => 'im_products']);
        $app['config']->set('naturalquery.default_dataset', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('im_orders');
        Schema::create('im_orders', function ($t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->decimal('revenue', 12, 2);
            $t->string('status');
        });
        DB::table('im_orders')->insert([
            ['customer_id' => 1, 'revenue' => 100, 'status' => 'paid'],
            ['customer_id' => 1, 'revenue' => 200, 'status' => 'paid'],
            ['customer_id' => 1, 'revenue' => 50, 'status' => 'cancelled'],
        ]);

        Schema::dropIfExists('im_customers');
        Schema::create('im_customers', function ($t) {
            $t->id();
            $t->string('name');
        });
        DB::table('im_customers')->insert([
            ['name' => 'Acme'],
        ]);
    }

    #[Test]
    public function an_incidental_alias_mention_widens_scope_instead_of_silently_narrowing_it()
    {
        $provider = new RecordingProvider();

        // What a real model plausibly does with what it was actually shown:
        // if im_orders' required_filter reached the prompt (i.e. scope
        // genuinely widened to include it, rather than the old cascade
        // silently dropping it) it applies the filter; nothing here decides
        // the outcome except what SchemaShortlister actually put in scope.
        $provider->sqlResponse = function (string $prompt) {
            $sawRequiredFilter = str_contains($prompt, "status != 'cancelled'");

            return [
                'success' => true,
                'data' => [
                    'sql' => $sawRequiredFilter
                        ? "SELECT SUM(revenue) AS total FROM im_orders WHERE status != 'cancelled'"
                        : 'SELECT SUM(revenue) AS total FROM im_orders',
                    'dataset' => 'im_orders',
                    'metric' => 'revenue',
                    'query_type' => 'aggregation',
                    'group_value' => null,
                    'order' => 'DESC',
                    'limit' => 100,
                    'period' => null,
                    'explanation' => 'Total revenue, paid orders only',
                ],
            ];
        };
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('total revenue from the gizmo line, excluding cancelled orders');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        // THE ASSERTION: 100 (paid) + 200 (paid) = 300, cancelled 50 excluded
        // by required_filter. A silent-narrowing regression would show up
        // here as a refusal (im_orders missing from scope entirely); a
        // silent-widening-gone-wrong regression would show up as 350 (the
        // model shown im_orders but not its required_filter, or the filter
        // ignored). Neither happens: the dataset is both included AND shown
        // in full.
        $this->assertEquals(
            300.0,
            (float) array_values((array) $result['rows'][0])[0],
            'a model actually shown im_orders (because scope widened rather than silently dropped it) '
                . 'must be able to read its required_filter and answer correctly. Got: ' . json_encode($result)
        );

        // Both datasets are genuinely in scope — "gizmo" routes to
        // im_products, "orders" names im_orders by its own alias, and
        // neither suppresses the other. im_customers rides along via
        // im_orders' one-hop FK expansion.
        $this->assertContains('im_products', $result['metadata']['datasets_in_scope'] ?? []);
        $this->assertContains('im_orders', $result['metadata']['datasets_in_scope'] ?? []);
        $this->assertEmpty($result['metadata']['datasets_omitted'] ?? null);

        // Forward guard: exactly one provider call, no retry — a well-formed
        // success should not trigger the refined-prompt retry path.
        $this->assertSame(1, count($provider->methodsCalled()), json_encode($provider->calls));
    }
}
