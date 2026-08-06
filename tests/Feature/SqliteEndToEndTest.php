<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Engine\SqlBuilder;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The path a new adopter actually takes, on the database `laravel new` gives
 * them: install, discover, ask. Nothing here is stubbed except the language
 * model, because the claim being tested is "it works out of the box on SQLite"
 * and a stub would prove only that the test agrees with itself.
 *
 * Answers are checked against hand-written gold SQL by execution accuracy —
 * the same standard used for the other drivers.
 */
class SqliteEndToEndTest extends TestCase
{
    private string $schemaPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaPath = sys_get_temp_dir() . '/nq-sqlite-e2e-' . getmypid();

        if (!is_dir($this->schemaPath)) {
            mkdir($this->schemaPath, 0777, true);
        }

        config(['naturalquery.schema.config_path' => $this->schemaPath]);

        Schema::create('shop_orders', function ($t) {
            $t->id();
            $t->string('customer_name');
            $t->string('region');
            $t->date('order_date');
            $t->decimal('revenue', 12, 2);
        });

        DB::table('shop_orders')->insert([
            ['customer_name' => 'Ada',  'region' => 'West', 'order_date' => '2026-07-05', 'revenue' => 300],
            ['customer_name' => 'Ada',  'region' => 'West', 'order_date' => '2026-08-05', 'revenue' => 700],
            ['customer_name' => 'Grace', 'region' => 'East', 'order_date' => '2026-07-09', 'revenue' => 500],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->schemaPath . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->schemaPath)) {
            rmdir($this->schemaPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function discover_generates_a_usable_schema_from_a_sqlite_database()
    {
        $exit = Artisan::call('naturalquery:discover', [
            '--table' => ['shop_orders'],
            '--output' => $this->schemaPath,
            '--no-verify' => true,
            '--force' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());

        $registry = $this->app->make(SchemaRegistry::class);
        $registry->flush();

        $schemas = $registry->all();
        $this->assertNotEmpty($schemas, 'discovery wrote no schema file: ' . Artisan::output());

        $key = array_key_first($schemas);
        $this->assertSame('shop_orders', $registry->getTableName($key));

        $columns = array_keys($schemas[$key]['tables']['primary']['columns']);
        foreach (['customer_name', 'region', 'order_date', 'revenue'] as $expected) {
            $this->assertContains($expected, $columns);
        }
    }

    #[Test]
    public function the_date_column_is_recognised_so_a_period_can_be_applied()
    {
        // If SQLite's DATE affinity were normalised to something else, every
        // "last month" question on a stock Laravel app would silently cover
        // all time — the exact defect this release set out to close.
        Artisan::call('naturalquery:discover', [
            '--table' => ['shop_orders'],
            '--output' => $this->schemaPath,
            '--no-verify' => true,
            '--force' => true,
        ]);

        $registry = $this->app->make(SchemaRegistry::class);
        $registry->flush();

        $this->assertSame('order_date', $registry->getDateColumn(array_key_first($registry->all())));
    }

    #[Test]
    public function a_question_is_answered_correctly_on_sqlite()
    {
        Artisan::call('naturalquery:discover', [
            '--table' => ['shop_orders'],
            '--output' => $this->schemaPath,
            '--no-verify' => true,
            '--force' => true,
        ]);

        $registry = $this->app->make(SchemaRegistry::class);
        $registry->flush();
        $this->app->forgetInstance(SqlBuilder::class);

        $key = array_key_first($registry->all());

        // "revenue by region in July 2026" — Ada's August order (700) must be
        // excluded, so West is 300 and not 1000.
        $built = $this->app->make(SqlBuilder::class)->buildQuery([
            'scheme' => $key,
            'metric' => 'revenue',
            'group_by' => 'region',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $this->assertTrue($built['success'], $built['error'] ?? '');

        $actual = [];
        foreach (DB::select($built['sql'], $built['bindings'] ?? []) as $row) {
            $actual[$row->region] = (float) $row->revenue;
        }

        $gold = [];
        foreach (DB::select(
            "SELECT region, SUM(revenue) AS revenue FROM shop_orders
             WHERE order_date >= '2026-07-01' AND order_date <= '2026-07-31'
             GROUP BY region"
        ) as $row) {
            $gold[$row->region] = (float) $row->revenue;
        }

        ksort($actual);
        ksort($gold);

        $this->assertSame(['East' => 500.0, 'West' => 300.0], $gold, 'gold fixture sanity');
        $this->assertSame($gold, $actual);
    }

    #[Test]
    public function doctor_reports_a_stock_sqlite_app_as_healthy()
    {
        Artisan::call('naturalquery:discover', [
            '--table' => ['shop_orders'],
            '--output' => $this->schemaPath,
            '--no-verify' => true,
            '--force' => true,
        ]);

        config(['naturalquery.feedback.enabled' => false]);
        $this->app->make(SchemaRegistry::class)->flush();

        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Connected (sqlite', $output);
        $this->assertStringNotContainsString('cannot introspect', $output);
    }
}
