<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Cache\TwoTierQueryCache;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Upgrading a 1.0.0 install to 2.0.0, where `scheme` became `dataset`.
 *
 * The rename itself is mechanical and the suite covers it by construction —
 * every fixture was renamed alongside the code, which is exactly why a green
 * suite proves nothing here. What a rename actually breaks is the data written
 * by the previous version: a column that still has the old name, and a cached
 * JSON blob that still has the old key inside it.
 *
 * These build 1.0.0-shaped rows deliberately and then upgrade them.
 */
class DatasetRenameUpgradeTest extends TestCase
{
    private function cacheTable(): string
    {
        return config('naturalquery.cache.table_name', 'naturalquery_cache');
    }

    /** Rebuild a table the way 1.0.0 left it: the column is called `scheme`. */
    private function makeLegacyCacheTable(string $name): void
    {
        Schema::dropIfExists($name);

        Schema::create($name, function ($table) {
            $table->id();
            $table->string('query_hash', 64)->unique()->index();
            $table->text('original_query');
            $table->text('normalized_query')->index();
            $table->string('scheme', 100)->nullable()->index();
            $table->string('metric', 100)->nullable();
            $table->json('intent')->nullable();
            $table->unsignedBigInteger('hit_count')->default(1);
            $table->timestamps();

            $table->index(['scheme', 'metric']);
        });
    }

    private function runRenameMigration(): void
    {
        (require __DIR__ . '/../../database/migrations/2026_08_12_000001_rename_scheme_column_to_dataset.php')->up();
    }

    #[Test]
    public function the_column_is_renamed_and_the_rows_survive()
    {
        $table = $this->cacheTable();
        $this->makeLegacyCacheTable($table);

        DB::table($table)->insert([
            'query_hash' => str_repeat('a', 64),
            'original_query' => 'total revenue',
            'normalized_query' => 'total revenue',
            'scheme' => 'orders',
            'metric' => 'revenue',
            'hit_count' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runRenameMigration();

        $this->assertFalse(Schema::hasColumn($table, 'scheme'));
        $this->assertTrue(Schema::hasColumn($table, 'dataset'));

        $row = DB::table($table)->first();
        $this->assertSame('orders', $row->dataset, 'the value did not survive the rename');
        $this->assertSame(7, (int) $row->hit_count, 'unrelated columns were disturbed');
    }

    /**
     * Every other consumer resolves the table name from config. A migration
     * that hardcodes it skips a renamed install in silence, leaving the cache
     * writing to a column that is not there — an error the cache swallows and
     * logs, so caching simply stops working forever with nothing user-visible.
     */
    #[Test]
    public function a_table_renamed_in_config_is_migrated_too()
    {
        config()->set('naturalquery.cache.table_name', 'nq_custom_cache');
        $this->makeLegacyCacheTable('nq_custom_cache');

        $this->runRenameMigration();

        $this->assertTrue(
            Schema::hasColumn('nq_custom_cache', 'dataset'),
            'a custom-named table was skipped, so its cache is now dead'
        );

        Schema::dropIfExists('nq_custom_cache');
    }

    /**
     * The guard has to hold, because the create migrations were edited in place
     * and a fresh install therefore never had a `scheme` column to rename.
     */
    #[Test]
    public function it_is_a_no_op_on_a_fresh_install()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $table = $this->cacheTable();

        $this->assertTrue(Schema::hasColumn($table, 'dataset'), 'precondition: migrated already');

        $this->runRenameMigration();

        $this->assertTrue(Schema::hasColumn($table, 'dataset'));
        $this->assertFalse(Schema::hasColumn($table, 'scheme'));
    }

    /**
     * The column rename does not reach inside the stored intent, which is JSON
     * and still says "scheme". Serving one to 2.0.0 yields a null dataset — a
     * "which dataset?" card for a question that used to work, and Tier 2 has no
     * expiry, so it would never age out on its own.
     *
     * Bumping the contract version makes those rows unreachable instead.
     */
    #[Test]
    public function a_row_cached_by_the_previous_contract_is_not_served()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $cache = app(TwoTierQueryCache::class);
        $question = 'total revenue by region';
        $normalized = $cache->normalizeQuery($question);

        // Exactly how 1.0.0 built the key: version 2.
        DB::table($this->cacheTable())->insert([
            'query_hash' => hash('sha256', '2|' . $normalized),
            'original_query' => $question,
            'normalized_query' => $normalized,
            'dataset' => 'orders',
            'metric' => 'revenue',
            'intent' => json_encode(['scheme' => 'orders', 'metric' => 'revenue']),
            'hit_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(
            $cache->find($question),
            'a 1.0.0 cache row was served to 2.0.0; its intent still says "scheme", so the dataset reads null'
        );
    }
}
