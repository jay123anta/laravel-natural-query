<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Cache\TwoTierQueryCache;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;
use Jayanta\NaturalQuery\Contracts\ScopesCacheByDataset;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Tests\Support\RecordingProvider;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The smaller half of the second review batch.
 *
 * None of these returns a wrong number. Each makes the package cost more,
 * degrade worse, or lie in a log — which is how the expensive ones started.
 */
class BatchTwoMinorsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('naturalquery.cache.enabled', true);
    }

    /**
     * A missing cache table is a miss, not a failed question.
     *
     * cache.enabled defaults to true, so an install that has not run
     * `php artisan migrate` queries a table that does not exist, and the
     * QueryException reached the orchestrator's catch: every question came
     * back "An error occurred processing your query", with nothing naming a
     * cache, a table or a migration. First thing a new adopter sees.
     */
    #[Test]
    public function a_missing_cache_table_degrades_to_a_miss_rather_than_failing_every_question()
    {
        Schema::dropIfExists(config('naturalquery.cache.table_name', 'naturalquery_cache'));
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert(['revenue' => 100]);

        config(['naturalquery.query_mode' => 'sql_generation']);

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                'dataset' => 'nq_orders',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a package installed but not migrated answers nothing at all, and says only "An error '
                . 'occurred processing your query": ' . json_encode($result)
        );
    }

    /**
     * A cache that declares the capability must be asked through it, whatever
     * class in its own hierarchy happens to declare find().
     */
    #[Test]
    public function a_non_bundled_scope_capable_cache_is_asked_for_the_scope()
    {
        $cache = new ConcreteScopedCache;
        $this->app->instance(QueryCacheInterface::class, $cache);
        $this->app->forgetInstance(QueryOrchestrator::class);

        config(['naturalquery.query_mode' => 'sql_generation']);

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => ['sql' => 'SELECT 1 AS revenue', 'dataset' => 'nq_orders', 'query_type' => 'aggregation'],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertGreaterThan(
            0,
            $cache->scopedCalls,
            'a cache that implements ScopesCacheByDataset was never asked through it, because find() '
                . 'happened to be declared on its own abstract base rather than on the concrete class'
        );
    }

    /** The fuzzy shortlist must be wide enough to survive a busy neighbour scope. */
    #[Test]
    public function the_fuzzy_shortlist_is_not_crowded_out_by_another_scope()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        // The threshold is not what is under test here, and leaving it at the
        // shipped 0.85 would need a wording pair engineered to clear it — which
        // makes the test about the similarity arithmetic instead of about the
        // shortlist. Lowered so the only thing that can decide the outcome is
        // whether the wanted row survived the candidate query.
        config([
            'naturalquery.cache.similarity_threshold' => 0.5,
            // Fuzzy matching is opt-in since 2.1.0; this test is about it.
            'naturalquery.cache.fuzzy_matching' => true,
        ]);

        $cache = $this->app->make(TwoTierQueryCache::class);
        $table = config('naturalquery.cache.table_name', 'naturalquery_cache');

        // The row we want, cached under nq_products and rarely used.
        $cache->store('total revenue by category for the year', [
            'dataset' => 'nq_products',
            'metric' => 'revenue',
            '_asking_scope' => 'nq_products',
        ]);

        // 60 much hotter rows under a different scope, all matching the same
        // significant words. Under the old shortlist of 50 these crowd the
        // wanted row out entirely.
        for ($i = 0; $i < 60; $i++) {
            DB::table($table)->insert([
                'query_hash' => hash('sha256', "filler-{$i}"),
                'contract_version' => 4,
                'original_query' => "total revenue by category for the year variant {$i}",
                'normalized_query' => $cache->normalizeQuery("total revenue by category for the year variant {$i}"),
                'dataset' => 'nq_orders',
                'intent' => json_encode(['dataset' => 'nq_orders', '_asking_scope' => 'nq_orders']),
                'hit_count' => 1000 + $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Different wording after normalisation — "years" is a distinct token
        // from "year", so this cannot be answered by the exact tier and must
        // go through fuzzy. (The earlier version of this test asked with a
        // leading "the", which is a filler word: both wordings normalised
        // identically, it was an exact hit, and the fuzzy tier was never
        // reached at all. It passed with the fix reverted.)
        $this->assertNotNull(
            $cache->findForDataset('total revenue by category for the years', 'nq_products'),
            'the fuzzy tier stopped working for a quiet scope because a busier one filled the shortlist'
        );
    }
}

/** A cache whose find() lives on its own base, not on TwoTierQueryCache. */
abstract class AdopterCacheBase implements QueryCacheInterface
{
    public function find(string $query): ?array
    {
        return null;
    }

    public function store(string $query, array $intent): bool
    {
        return true;
    }

    public function getStatistics(): array
    {
        return [];
    }

    public function clear(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        return 0;
    }
}

class ConcreteScopedCache extends AdopterCacheBase implements ScopesCacheByDataset
{
    public int $scopedCalls = 0;

    public function findForDataset(string $query, ?string $datasetHint = null): ?array
    {
        $this->scopedCalls++;

        return null;
    }
}
