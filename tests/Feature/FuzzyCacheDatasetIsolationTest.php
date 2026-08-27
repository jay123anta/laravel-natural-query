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
 * NQ-003, RED for G2.
 *
 * `TwoTierQueryCache::find(string $query)` (src/Cache/TwoTierQueryCache.php)
 * takes ONLY the normalized question text. Nothing about which dataset the
 * ASKING question resolves to is ever consulted before a cached row is
 * served, on any of its routes:
 *
 *   - Tier 1 (fast KV store): keyed by `sha256(CONTRACT_VERSION . normalized
 *     text)`. `$datasetHint` -  the out-of-band signal a caller can supply
 *     (`QueryOrchestrator::query($q, $datasetHint)`; a widget embedded on a
 *     page already scoped to one dataset is the real-world source) -  is
 *     never part of the hash and is never even passed to `find()`.
 *   - Tier 2 exact: same hash, same gap.
 *   - Tier 2 fuzzy (`findFuzzyMatch()`): selects candidates by `LIKE` on
 *     significant words and ranks by `calculateSimilarity()` against
 *     `cache.similarity_threshold`. The `dataset` column the row already
 *     stores is never read.
 *
 * All three feed the SAME cached branch in
 * `QueryOrchestrator::processWithSqlGeneration()` -  `if ($cached &&
 * isset($cached['intent']['_sql_result'])) { return
 * $this->validateAndExecute($sqlResult, ...); }` -  which returns before any
 * prompt is built and before dataset detection (seeder/routing/LLM) ever
 * runs. That is the ONLY place a wrong-dataset SQL result could otherwise be
 * caught, so a cache hit that crosses datasets is never caught downstream
 * either.
 *
 * Routes enumerated and checked in this file:
 *   - Tier 2 fuzzy, different wording, no dataset hint  → test (a)
 *   - Tier 2 fuzzy, SAME dataset (must keep working)     → test (b)
 *   - Tier 1 exact, identical wording, changed dataset hint → test (c)
 * NQ-003-FIX: both (a) and (c) were rewritten, because NQ-003's own fix for
 * this file's original defect was itself unsafe -  a silent retarget through
 * `SqlBuilder` rather than a refusal -  and the `_sql_result` branch that
 * retarget lived in does not distinguish Tier 1 exact from Tier 2 fuzzy; it
 * runs identically for both. (a) -  `a_fuzzy_hit_must_not_replay_a_different_datasets_answer`
 * -  kept its name (still true: a mismatch no longer replays anything) but its
 * body now expects a MISS, the same change made to (c) -
 * `an_exact_tier1_candidate_with_a_changed_dataset_hint_is_a_cache_miss`,
 * formerly `an_exact_tier1_hit_must_not_ignore_a_changed_dataset_hint`. See
 * each test's docblock and `tests/Feature/CacheDatasetMismatchMissTest.php`.
 * NOT covered here: the conversation-turn path. `processWithIntent()`
 * explicitly disables cache USE while `$context['state']` is non-empty
 * (`if ($cached && !$inConversation)`), but `processWithSqlGeneration()`'s
 * `_sql_result` branch has no equivalent guard -  a follow-up could hit this
 * same gap. Left for the Adversarial Reviewer: it is the same structural
 * defect as (a)/(c) above, not a fourth mechanism, so a fix that makes
 * `find()`/the `_sql_result` branch itself dataset-aware closes it too; a
 * fix that only special-cases `findFuzzyMatch()` would not.
 *
 * No scoping feature is involved anywhere below. NQ-001-v2's per-question
 * dataset scoping (`PromptScope`, `SchemaShortlister`, `QueryOrchestrator::
 * resolveScope()`) was removed outright by NQ-001-REDUCE -  the G1-round-3
 * ruling struck it out entirely, keeping only `PromptBudget`'s bare
 * `prompts.max_chars` size refusal. An earlier revision of that now-deleted
 * scoping feature happened to catch this exact cross-dataset scenario as a
 * SIDE EFFECT when it was turned on, in what was then `PromptScopeGateTest`
 * (also since removed) -  which passed for that reason, not because the
 * cache itself was fixed. This file proves the cache is broken on its own,
 * with nothing else involved.
 */
class FuzzyCacheDatasetIsolationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('naturalquery.query_mode', 'sql_generation');
        $app['config']->set('naturalquery.cache.enabled', false);
        $app['config']->set('naturalquery.query_routing', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert([
            ['revenue' => 100],
            ['revenue' => 200],
            ['revenue' => 50],
        ]);
        // Hand-checked: 100 + 200 + 50 = 350.

        Schema::dropIfExists('nq_products');
        Schema::create('nq_products', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_products')->insert([
            ['revenue' => 30],
            ['revenue' => 45],
            ['revenue' => 15],
        ]);
        // Hand-checked: 30 + 45 + 15 = 90.
    }

    /**
     * What a real model would plausibly write: it can only name a table it
     * was actually shown IN ITS PROMPT for THIS call. The closure does not
     * decide the test's outcome -  it only reports which single-dataset
     * prompt was actually built for whichever call reaches the provider,
     * which a cache hit (correctly or incorrectly) can skip entirely.
     */
    private function wiredProvider(): RecordingProvider
    {
        $provider = new RecordingProvider;

        $provider->sqlResponse = function (string $prompt) {
            $sawProducts = str_contains(strtolower($prompt), 'nq_products');

            return [
                'success' => true,
                'data' => [
                    'sql' => $sawProducts
                        ? 'SELECT SUM(revenue) AS total FROM nq_products'
                        : 'SELECT SUM(revenue) AS total FROM nq_orders',
                    'dataset' => $sawProducts ? 'nq_products' : 'nq_orders',
                    'metric' => 'revenue',
                    'query_type' => 'aggregation',
                    'group_value' => null,
                    'order' => 'DESC',
                    'limit' => 100,
                    'period' => null,
                    'explanation' => 'Total revenue',
                ],
            ];
        };

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    private function total(array $result): float
    {
        return (float) array_values((array) $result['rows'][0])[0];
    }

    /**
     * WHAT THIS TEST CATCHES: a fuzzy cache hit replaying a DIFFERENT
     * dataset's cached SQL for a question that names its own dataset by its
     * own alias word ("products"), because `findFuzzyMatch()` scores
     * candidates on text alone.
     *
     * Similarity is measured directly against the shipped
     * `calculateSimilarity()` algorithm, not the approximate `similar_text()`
     * figure the original defect report used: "revenue summary for the
     * orders channel" vs "revenue summary for the products channel" scores
     * 0.6975 (0.6 * Jaccard 0.6 + 0.4 * Levenshtein-similarity 0.84375).
     * `similarity_threshold` is set to 0.55 -  comfortably below that, and
     * still a majority-overlap bar, not a degenerate one -  purely to make
     * the reproduction deterministic. The gap being pinned is structural:
     * the matcher has no dataset field to consult at ANY threshold loose
     * enough to catch real paraphrases (the shipped config comments its own
     * "top 10" vs "bottom 10" = 0.65 warning), which is exactly why NQ-003's
     * fix direction rules out "raise the threshold" as an answer.
     *
     * NQ-003-FIX: this used to assert the fuzzy hit was retargeted for FREE
     * (zero extra provider calls) onto the right number, because in this
     * predicate-free fixture `SqlBuilder::buildQuery()`'s retarget happens to
     * recompute a correct `SUM(revenue)` against nq_products. That is the
     * same unsafe mechanism `CacheDatasetMismatchMissTest`'s Blocker A test
     * shows dropping a WHERE clause elsewhere -  the `_sql_result` branch does
     * not know it is looking at a fuzzy candidate rather than an exact one,
     * so it cannot be safe here and unsafe there. A dataset mismatch is now a
     * MISS on every route into that branch, fuzzy included: one fresh
     * provider call, never a replay and never a retarget.
     */
    #[Test]
    public function a_fuzzy_hit_must_not_replay_a_different_datasets_answer()
    {
        config([
            'naturalquery.cache.enabled' => true,
            // Fuzzy matching is opt-in since 2.1.0; this file tests that tier.
            'naturalquery.cache.fuzzy_matching' => true,
            'naturalquery.cache.similarity_threshold' => 0.55,
        ]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $q1 = 'revenue summary for the orders channel';
        $q2 = 'revenue summary for the products channel';

        $first = $this->app->make(QueryOrchestrator::class)->query($q1);

        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(350.0, $this->total($first), 'precondition: nq_orders total is 350 (100+200+50)');
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $this->app->make(QueryOrchestrator::class)->query($q2);

        // The fuzzy candidate names nq_orders; the question actually asked
        // resolves (via its own alias word "products") to nq_products. That
        // mismatch must cost exactly one fresh provider call -  not zero
        // (replayed or retargeted for free), not two.
        $this->assertSame(
            $callsAfterFirst + 1,
            count($provider->methodsCalled()),
            'a fuzzy candidate naming a different dataset must MISS and cost exactly one fresh provider call: '
                . json_encode($provider->calls)
        );

        $this->assertSame('success', $second['status'] ?? null, json_encode($second));

        // nq_products' own total (30+45+15), obtained fresh -  not nq_orders'
        // 350 replayed, and not a retargeted-but-unverified recipe.
        $this->assertEquals(
            90.0,
            $this->total($second),
            'a fuzzy cache hit must answer from the dataset actually asked about, freshly generated. Got: ' . json_encode($second)
        );
    }

    /**
     * WHAT THIS TEST CATCHES (guard, not a defect): a fix that closes the
     * cross-dataset gap by disabling fuzzy matching outright, rather than
     * making it dataset-aware, would make this fail too. Two genuine
     * paraphrases of the SAME dataset's question -  "revenue summary for the
     * orders channel" vs "orders channel revenue overview", measured at
     * 0.5923 by the real algorithm -  must still answer from cache with zero
     * extra provider calls. If a later fix makes this red, that is the
     * honest cost of the fix, not something to hide by deleting the test.
     */
    #[Test]
    public function a_fuzzy_hit_within_the_same_dataset_still_answers_from_cache()
    {
        config([
            'naturalquery.cache.enabled' => true,
            // Fuzzy matching is opt-in since 2.1.0; this file tests that tier.
            'naturalquery.cache.fuzzy_matching' => true,
            'naturalquery.cache.similarity_threshold' => 0.55,
        ]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $q1 = 'revenue summary for the orders channel';
        $q3 = 'orders channel revenue overview';

        $first = $this->app->make(QueryOrchestrator::class)->query($q1);
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(350.0, $this->total($first));

        $second = $this->app->make(QueryOrchestrator::class)->query($q3);

        $this->assertSame(
            1,
            count($provider->methodsCalled()),
            'expected the paraphrase to be served from cache, not a second provider call: '
                . json_encode($provider->calls)
        );
        $this->assertSame('fuzzy', $second['metadata']['cache_match_type'] ?? null, json_encode($second));
        $this->assertSame('success', $second['status'] ?? null, json_encode($second));
        $this->assertEquals(
            350.0,
            $this->total($second),
            'a genuine same-dataset paraphrase should still answer 350 from cache'
        );
    }

    /**
     * WHAT THIS TEST CATCHES (NQ-003-FIX): NQ-003's first fix closed the
     * Tier 1 gap this test used to pin by RETARGETING a mismatched hit
     * through `SqlBuilder::buildQuery()` instead of refusing it -  zero
     * provider calls, and (in this predicate-free fixture) numerically
     * right by accident, because `buildQuery()` recomputes a fresh
     * `SUM(revenue)` against whichever table it is pointed at rather than
     * replaying stored numbers. That reconciliation is itself unsafe (see
     * `CacheDatasetMismatchMissTest`'s Blocker A test, where the same
     * mechanism silently drops a WHERE clause the cached recipe cannot
     * carry) and NQ-003-FIX deletes it: a dataset mismatch on an
     * exact-hash Tier 1 candidate is now a cache MISS, costing exactly one
     * fresh provider call, never a silent retarget.
     *
     * Renamed from `an_exact_tier1_hit_must_not_ignore_a_changed_dataset_hint`
     * -  that name and its old assertions (a Tier 1 HIT, zero provider
     * calls, AND the other dataset's number, all three at once) are only
     * jointly satisfiable through the retarget this test now forbids.
     *
     * Tier 1 keys purely off `sha256(CONTRACT_VERSION .
     * normalizeQuery($query))` -  the same literal words always hash to the
     * same row, and `$datasetHint` never enters that hash. A question with
     * no dataset-identifying word of its own ("what is the total") depends
     * entirely on `$datasetHint` for which table it means; asking the exact
     * same words twice with two different hints must MISS, not silently
     * answer (or silently retarget to) the first call's dataset.
     */
    #[Test]
    public function an_exact_tier1_candidate_with_a_changed_dataset_hint_is_a_cache_miss()
    {
        config(['naturalquery.cache.enabled' => true]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $question = 'what is the total';

        $first = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(350.0, $this->total($first), 'precondition: nq_orders total is 350 (100+200+50)');
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_products');

        // THE RED ASSERTION. Today this is $callsAfterFirst (zero extra
        // calls) because the mismatch is retargeted through SqlBuilder
        // instead of missing -  not reconciled for free, not two calls.
        $this->assertSame(
            $callsAfterFirst + 1,
            count($provider->methodsCalled()),
            'a dataset mismatch on an exact-hash Tier 1 candidate must MISS and cost exactly one fresh provider call, not be reconciled for free: '
                . json_encode($provider->calls)
        );

        $this->assertSame('success', $second['status'] ?? null, json_encode($second));

        // nq_products' own total (30+45+15), obtained from a fresh
        // generation about the dataset actually asked about -  not
        // nq_orders' 350 replayed, and not a retargeted-but-unverified 90.
        $this->assertEquals(
            90.0,
            $this->total($second),
            'a dataset mismatch must answer from the dataset actually asked about, freshly generated. Got: '
                . json_encode($second)
        );
    }
}
