<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Contracts\SchemaIntrospectorInterface;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Schema\Introspectors\MysqlIntrospector;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\Support\RecordingProvider;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package must work against any application's database, and the rest of
 * the suite only proves it works against two shapes it already knows: an
 * orders table and a districts table, both written for these tests.
 *
 * This one uses a schema with unfamiliar vocabulary, no declared
 * `group_column`, and no column called `name` — the fallback the engine used
 * to assume when `group_column` was missing, which produced
 * `SELECT name ... GROUP BY name` and a hard SQL error on any table without
 * one. `naturalquery:discover` always writes group_column, but the README
 * documents hand-written schema files, and those are exactly the ones that
 * leave it out.
 */
class UnfamiliarSchemaTest extends TestCase
{
    private RecordingProvider $provider;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/unfamiliar-schemas');
        $app['config']->set('database.default', 'nq_unfamiliar');
        $app['config']->set('database.connections.nq_unfamiliar', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('naturalquery.query_mode', 'intent');
        $app['config']->set('naturalquery.errors.retry_on_failure', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('nq_warehouse_stock', function (Blueprint $table) {
            $table->string('bin_code');
            $table->integer('units_held');
        });

        DB::table('nq_warehouse_stock')->insert([
            ['bin_code' => 'A-01', 'units_held' => 120],
            ['bin_code' => 'A-01', 'units_held' => 30],
            ['bin_code' => 'B-07', 'units_held' => 75],
        ]);

        $this->app->instance(SchemaIntrospectorInterface::class, new MysqlIntrospector());

        $this->provider = new RecordingProvider();
        $this->provider->intentResponse = [
            'success' => true,
            'scheme' => 'warehouse_stock',
            'metric' => 'units_held',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];
        $this->app->instance(LlmProviderInterface::class, $this->provider);
    }

    #[Test]
    public function the_group_column_is_derived_from_the_schema_not_assumed_to_be_name()
    {
        $groupColumn = $this->app->make(SchemaRegistry::class)->getGroupColumn('warehouse_stock');

        $this->assertSame('bin_code', $groupColumn);
        $this->assertNotSame('name', $groupColumn, 'no such column exists on this table');
    }

    #[Test]
    public function a_query_runs_end_to_end_against_an_unfamiliar_table()
    {
        $result = $this->app->make(QueryOrchestrator::class)->query('top bins by units held');

        $this->assertSame('success', $result['status'], $result['error'] ?? '');
        $this->assertNotEmpty($result['rows']);
    }

    #[Test]
    public function the_measure_is_summed_per_group_on_an_unfamiliar_table()
    {
        $result = $this->app->make(QueryOrchestrator::class)->query('top bins by units held');

        $rows = array_map(fn ($r) => (array) $r, $result['rows']);
        $byBin = array_column($rows, 'units_held', 'bin_code');

        // A-01 appears twice and must be totalled, not listed twice.
        $this->assertSame(2, count($rows));
        $this->assertEquals(150, $byBin['A-01']);
        $this->assertEquals(75, $byBin['B-07']);
    }

    #[Test]
    public function the_answer_is_labelled_with_the_real_grouping_column()
    {
        $result = $this->app->make(QueryOrchestrator::class)->query('top bins by units held');

        // Falling back to a non-existent 'name' column produced "?" labels.
        $this->assertStringContainsString('A-01', $result['answer']);
        $this->assertStringNotContainsString('?', $result['answer']);
    }

    #[Test]
    public function no_row_data_from_an_unfamiliar_table_reaches_the_provider()
    {
        $this->app->make(QueryOrchestrator::class)->query('top bins by units held');

        $sent = json_encode($this->provider->calls);

        foreach (['A-01', 'B-07', '120', '150'] as $value) {
            $this->assertStringNotContainsString($value, $sent);
        }
    }
}
