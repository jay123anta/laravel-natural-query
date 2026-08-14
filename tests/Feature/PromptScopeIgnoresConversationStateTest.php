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
 * NQ-001-v2, RED for G2.
 *
 * The design's own I10 list of signals SchemaShortlister may seed scope from
 * names "conversation state dataset" explicitly, alongside default_dataset
 * and query_routing. But QueryOrchestrator::resolveScope() does not take
 * $context at all:
 *
 *   protected function resolveScope(string $query, ?string $datasetHint): ?PromptScope
 *
 * — called as resolveScope($query, $datasetHint) from inside
 * processWithSqlGeneration(), which HAS $context available and simply never
 * passes it through. So a follow-up turn whose own text names no dataset —
 * "and only the paid ones, please", continuing a conversation already
 * established on `sc_orders` — is seeded purely from DatasetSeeder::seeds(),
 * which falls through to naturalquery.default_dataset when nothing else in
 * the question's own text matches. A project that sets default_dataset to
 * its most commonly queried table (here, `sc_products` — an entirely
 * ordinary thing to configure) then has every such follow-up scoped to THAT
 * table instead of the one the conversation is actually about. The model,
 * correctly continuing the conversation from $context['state']['dataset'],
 * replies with SQL against `sc_orders` — and R6's scope gate refuses it,
 * because `sc_orders` was never in a scope that was never told the
 * conversation existed.
 *
 * WHAT THIS TEST CATCHES: a legitimate, correctly-answered follow-up in an
 * ordinary multi-turn conversation is turned into a CANNOT_ANSWER refusal
 * the moment an adopter turns prompts.max_chars on — a capability that
 * worked before this feature shipped (scope null throughout, C1) silently
 * breaks after, for a signal the design's own I10 list says must be
 * honoured. This is not a size refusal (the fixture is tiny and
 * prompts.max_chars is generous) — it is the scope GATE rejecting the
 * dataset the conversation is genuinely about, which is exactly the "wrong
 * refusal for a right answer" failure mode R4/R6 exist to avoid, not cause.
 *
 * Fixture: tests/Stubs/scope-gate-schemas (already checked in for
 * PromptScopeGateTest) — sc_orders has required_filter excluding cancelled
 * rows; sc_products is unrelated, no FK either way. Hand-checkable: revenue
 * 100 (paid) + revenue 200 (paid) + revenue 50 (cancelled) — a correctly
 * scoped, correctly filtered answer is 100 + 200 = 300.
 */
class PromptScopeIgnoresConversationStateTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/scope-gate-schemas');
        $app['config']->set('naturalquery.query_mode', 'sql_generation');
        $app['config']->set('naturalquery.cache.enabled', false);

        // Activates the new scope mechanism (C1: null == today's byte-identical
        // behaviour, unbounded). Generous — nothing here is meant to trip the
        // SIZE refusal, only to turn scoping on so the R6 gate is live.
        $app['config']->set('naturalquery.prompts.max_chars', 50000);

        // An entirely ordinary configuration: two routed datasets, and a
        // default for whatever a question does not otherwise name. Neither
        // routing rule fires on the follow-up's own wording below.
        $app['config']->set('naturalquery.query_routing', [
            'orders' => 'sc_orders',
            'products' => 'sc_products',
        ]);
        $app['config']->set('naturalquery.default_dataset', 'sc_products');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sc_orders');
        Schema::create('sc_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
            $t->string('status');
        });

        DB::table('sc_orders')->insert([
            ['revenue' => 100, 'status' => 'paid'],
            ['revenue' => 200, 'status' => 'paid'],
            ['revenue' => 50, 'status' => 'cancelled'],
        ]);
    }

    #[Test]
    public function a_followup_naming_nothing_is_still_scoped_to_the_conversations_own_dataset()
    {
        $provider = new RecordingProvider();
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                // Correctly continues the conversation on sc_orders, and
                // correctly applies the schema's required_filter — exactly
                // what a model actually SHOWN sc_orders (because scope had
                // included it, as it should have) would be expected to write.
                'sql' => "SELECT SUM(revenue) AS total FROM sc_orders WHERE status != 'cancelled'",
                'dataset' => 'sc_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
                'group_value' => null,
                'order' => 'DESC',
                'limit' => 100,
                'period' => null,
                'explanation' => 'Total revenue, paid orders only',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        // Exactly the shape ConversationManager::query() builds after a first
        // turn established dataset=sc_orders: ['state' => $carried->toIntent()]
        // (see src/Conversation/ConversationManager.php:87). The follow-up's
        // own words name neither "orders" nor "products".
        $result = $this->app->make(QueryOrchestrator::class)->query(
            'and only the paid ones, please',
            null,
            ['state' => ['dataset' => 'sc_orders', 'metric' => 'revenue']]
        );

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            "a follow-up correctly continuing a conversation about sc_orders (per \$context['state']) "
                . 'was refused instead of answered. Got: ' . json_encode($result)
        );
        $this->assertEquals(
            300.0,
            (float) array_values((array) $result['rows'][0])[0],
            'hand-checked: 100 (paid) + 200 (paid) = 300, cancelled 50 excluded by required_filter'
        );
    }
}
