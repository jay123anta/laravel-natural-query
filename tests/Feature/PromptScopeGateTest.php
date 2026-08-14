<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Engine\ErrorCode;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Events\UnsafeSqlRejected;
use Jayanta\NaturalQuery\Tests\Support\RecordingProvider;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-v2, RED for G2.
 *
 * Today `SqlValidator::validate()` accepts ANY table that appears somewhere
 * in `SchemaRegistry::getAllowedTables()` — the whitelist is global, not
 * per-question. So the moment a reply names a table that belongs to a
 * DIFFERENT dataset than the one the question actually seeded to, nothing
 * stops it: it is a whitelisted table, the query executes, and a confident
 * number comes back for a question the schema never scoped the reply to.
 * That is the exact failure Rule 0 names as the worst outcome this package
 * can produce.
 *
 * `tests/Stubs/scope-gate-schemas/` has two datasets with NO foreign key
 * between them — `sc_orders` (revenue, status, required_filter excluding
 * cancelled rows) and `sc_products` (unrelated). Rows, hand-checkable:
 *
 *   revenue 100 (paid) + revenue 200 (paid) + revenue 50 (cancelled)
 *   non-cancelled total = 100 + 200       = 300
 *   all-rows total      = 100 + 200 + 50  = 350
 *
 * WHAT THIS TEST CATCHES: once a question is seeded to `sc_products` (via a
 * `query_routing` keyword — the same signal `buildRoutingHints()` already
 * renders and the design's I10 list already permits) a reply naming
 * `sc_orders` must be REFUSED before execution — not run and returned as a
 * "success" carrying the unfiltered, all-rows 350, which is what happens
 * today. There is no code path today that gates SQL against anything
 * narrower than the full cross-dataset whitelist, so this fails now for
 * that reason: the assertion below expects `error`/`cannot_answer` and the
 * orchestrator currently returns `success` with rows totalling 350.
 *
 * NQ-001-v3 UPDATE: the first test below originally asked "... excluding
 * cancelled ORDERS", relying on `DatasetSeeder::seeds()`'s old winner-takes-
 * all cascade to suppress that incidental "orders" mention once "gizmo" had
 * routed the question elsewhere. That cascade is also what caused defect 1
 * (a question NAMING a dataset outright — "which products bring in the most
 * revenue" — had the mention silently dropped the exact same way). The two
 * behaviours are the same mechanism, and I10's permitted-signal list gives
 * no way to tell "orders" naming a real second topic apart from "orders"
 * used incidentally — both are a bare alias match in the question text. §0
 * resolves the tie: a scope too NARROW risks a silent wrong number with no
 * way back; a scope too WIDE risks, at worst, a bigger prompt or a loud
 * budget refusal. `DatasetSeeder::seeds()` now MERGES every tier instead of
 * cascading, so the question below was reworded to remove the incidental
 * collision it depended on — the gate this test pins (a table with NO
 * seeding signal at all must still be refused) is unaffected by the merge.
 * The ORIGINAL wording, and what "merged" scope now does with it instead of
 * refusing, is pinned separately by
 * `Jayanta\NaturalQuery\Tests\Feature\PromptScopeIncidentalMentionTest`, on a
 * different fixture — this one's two datasets share no foreign key
 * anywhere, so `hasLinkedSchemas()` is false registry-wide and every
 * question here is routed through the single-dataset auto-mode prompt
 * regardless of `$scope` (the separately-filed "auto-mode fallback... on an
 * unlinked schema" issue), which is the wrong fixture to demonstrate what a
 * WIDENED multi-dataset prompt actually shows a model.
 */
class PromptScopeGateTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/scope-gate-schemas');
        $app['config']->set('naturalquery.query_mode', 'sql_generation');
        $app['config']->set('naturalquery.cache.enabled', false);

        // Activates the new scope mechanism (C1: null == today's byte-identical
        // behaviour, unbounded). The value is generous — nothing in this test
        // is meant to trip the SIZE refusal, only to turn scoping on so R6's
        // gate is live.
        $app['config']->set('naturalquery.prompts.max_chars', 50000);

        // The wrong-seed lever: "gizmo" appears in the question but has
        // nothing to do with orders. A routing rule pointing it at the
        // UNRELATED dataset is exactly the kind of misconfiguration R3/property
        // 3 says must be honoured — routing is supreme, even when it is wrong,
        // and the resulting scope violation must be refused rather than quietly
        // executed against the correct-looking table the reply names instead.
        $app['config']->set('naturalquery.query_routing', ['gizmo' => 'sc_products']);
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

    private function wiredProvider(array $sqlResponse): RecordingProvider
    {
        $provider = new RecordingProvider();
        $provider->sqlResponse = $sqlResponse;

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    /** A reply that ignores sc_orders' required_filter — exactly what a model
     *  never shown sc_orders (because the question was scoped to sc_products)
     *  would plausibly write if it hallucinated the table name anyway. */
    private function unfilteredOrdersReply(): array
    {
        return [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS total FROM sc_orders',
                'dataset' => 'sc_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
                'group_value' => null,
                'order' => 'DESC',
                'limit' => 100,
                'period' => null,
                'explanation' => 'Total revenue',
            ],
        ];
    }

    #[Test]
    public function a_reply_naming_a_table_outside_the_seeded_scope_is_refused_not_executed_unfiltered()
    {
        Event::fake([UnsafeSqlRejected::class]);
        $this->wiredProvider($this->unfilteredOrdersReply());

        // Reworded from "... excluding cancelled ORDERS" (see the class
        // docblock's NQ-001-v3 UPDATE note): that wording also contained
        // sc_orders' own alias, which under the now-fixed cascade bug used
        // to be suppressed by the "gizmo" routing match — an accident of the
        // defect, not a real gate. "records" carries no seeding signal for
        // either dataset, so sc_orders reaches this reply with NOTHING
        // pointing scope at it, which is the genuine out-of-scope case R6
        // exists to catch.
        $result = $this->app->make(QueryOrchestrator::class)
            ->query('total revenue from the gizmo line, excluding cancelled records');

        // THE RED ASSERTION. Today this is 'success' with one row totalling
        // 350 — the all-rows figure, not the hand-checked 300 a correctly
        // scoped and filtered query would need, and not a refusal either.
        // Both are wrong outcomes; only a refusal is honest about what
        // happened, per R4/R6.
        $this->assertSame(
            'error',
            $result['status'] ?? null,
            'a reply naming a table outside the seeded scope must be refused, not executed. Got: '
                . json_encode($result)
        );
        $this->assertSame(ErrorCode::CANNOT_ANSWER, $result['error_code'] ?? null);

        // Forward guard, already true today for the trivial reason that no
        // gate exists yet to reject anything: once R6 exists, a scope
        // violation must still be routed to CANNOT_ANSWER and NOT reuse the
        // security dispatch — UnsafeSqlRejected is for the whitelist SqlValidator
        // pass, not the per-question scope pass, per the design's explicit
        // "dispatch NOTHING".
        Event::assertNotDispatched(UnsafeSqlRejected::class);
    }

    /**
     * A fuzzy cache hit crossing datasets is NOT a scoping concern — it is
     * the cache's own defect (NQ-003), and this class previously carried
     * that case with `prompts.max_chars` turned on for R6's scope gate. That
     * config caught the crossing as a SIDE EFFECT of the per-question
     * whitelist below, which is exactly the kind of accidental protection
     * that hides a defect rather than fixing it: this test passed while
     * `TwoTierQueryCache::findFuzzyMatch()` (and Tier 1's exact hash) still
     * had no idea which dataset a question was about. Re-homed, with
     * scoping OFF entirely, to
     * `Jayanta\NaturalQuery\Tests\Feature\FuzzyCacheDatasetIsolationTest`,
     * where it fails today for the real reason.
     */
}
