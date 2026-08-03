<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jayanta\NaturalQuery\Schema\Introspectors\PostgresIntrospector;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the real introspector against a real PostgreSQL server.
 *
 * Everything else in the suite runs on SQLite so it needs no services, which
 * means the Postgres SQL itself — the queries `naturalquery:discover` depends
 * on — had no coverage at all. These cases exist because of a bug that could
 * only ever show up here: `listTables()` estimated row counts with a subquery
 * matching on `relname` alone, and `pg_class` is database-wide, so the first
 * database with the same table name in two schemas raised
 * "more than one row returned by a subquery used as an expression" and
 * discovery died. One schema per subject area is a completely ordinary
 * Postgres layout.
 *
 * Skips when no server is reachable, so local runs stay service-free; CI
 * provides one.
 */
class PostgresIntrospectorTest extends TestCase
{
    private const CONNECTION = 'nq_pgtest';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.' . self::CONNECTION, [
            'driver' => 'pgsql',
            'host' => env('NQ_PGSQL_HOST', '127.0.0.1'),
            'port' => env('NQ_PGSQL_PORT', '5432'),
            'database' => env('NQ_PGSQL_DATABASE', 'postgres'),
            'username' => env('NQ_PGSQL_USERNAME', 'postgres'),
            'password' => env('NQ_PGSQL_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection(self::CONNECTION)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No PostgreSQL server reachable: ' . $e->getMessage());
        }

        $this->tearDownFixtures();

        $conn = DB::connection(self::CONNECTION);

        // Two schemas holding a table of the same name — the layout that broke.
        foreach (['nq_alpha', 'nq_beta'] as $schema) {
            $conn->statement("create schema {$schema}");
            $conn->statement("create table {$schema}.applications (
                id serial primary key,
                district varchar(80),
                pending integer
            )");
            $conn->statement("comment on table {$schema}.applications is 'Applications for {$schema}'");
            $conn->statement("comment on column {$schema}.applications.pending is 'Pending count'");
            $conn->statement("insert into {$schema}.applications (district, pending) values ('Kamrup', 10), ('Nagaon', 20)");
        }

        // A mixed-case name, which an unquoted ::regclass cast cannot resolve.
        $conn->statement('create table nq_alpha."MixedCase" (id serial primary key)');
        $conn->statement('analyze');
    }

    protected function tearDown(): void
    {
        $this->tearDownFixtures();
        parent::tearDown();
    }

    private function tearDownFixtures(): void
    {
        try {
            $conn = DB::connection(self::CONNECTION);
            foreach (['nq_alpha', 'nq_beta'] as $schema) {
                $conn->statement("drop schema if exists {$schema} cascade");
            }
        } catch (\Throwable $e) {
            // Nothing to clean up.
        }
    }

    private function introspector(): PostgresIntrospector
    {
        config(['naturalquery.sql.database_connection' => self::CONNECTION]);

        return new PostgresIntrospector();
    }

    #[Test]
    public function it_lists_tables_when_two_schemas_share_a_table_name()
    {
        $tables = $this->introspector()->listTables(self::CONNECTION, ['nq_alpha', 'nq_beta']);

        $names = array_column($tables, 'name');

        $this->assertContains('nq_alpha.applications', $names);
        $this->assertContains('nq_beta.applications', $names);
    }

    #[Test]
    public function it_reports_a_row_estimate_per_schema_rather_than_per_table_name()
    {
        $tables = $this->introspector()->listTables(self::CONNECTION, ['nq_alpha', 'nq_beta']);

        foreach ($tables as $table) {
            if ($table['name'] === 'nq_alpha.applications') {
                $this->assertSame(2, $table['row_estimate']);
            }
        }
    }

    #[Test]
    public function it_reads_the_table_comment_for_each_schema_separately()
    {
        $tables = collect($this->introspector()->listTables(self::CONNECTION, ['nq_alpha', 'nq_beta']))
            ->keyBy('name');

        $this->assertSame('Applications for nq_alpha', $tables['nq_alpha.applications']['comment']);
        $this->assertSame('Applications for nq_beta', $tables['nq_beta.applications']['comment']);
    }

    #[Test]
    public function it_handles_a_mixed_case_table_name()
    {
        // Unquoted identifier concatenation makes ::regclass throw
        // "relation does not exist" here, aborting the whole listing.
        $names = array_column($this->introspector()->listTables(self::CONNECTION, ['nq_alpha']), 'name');

        $this->assertContains('nq_alpha.MixedCase', $names);
    }

    #[Test]
    public function it_reads_columns_with_types_comments_and_primary_keys()
    {
        $columns = collect($this->introspector()->getColumns('nq_alpha.applications', self::CONNECTION))
            ->keyBy('name');

        $this->assertTrue($columns->has('district'));
        $this->assertSame('Pending count', $columns['pending']['comment']);
        $this->assertTrue((bool) $columns['id']['is_primary']);
        // 'district' here is a real column in the fixture table, not the
        // intent contract's field — an application is perfectly entitled to
        // have a column called district.
        $this->assertFalse((bool) $columns['district']['is_primary']);
    }

    #[Test]
    public function it_reads_indexes_scoped_to_the_right_schema()
    {
        $indexes = $this->introspector()->getIndexes('nq_alpha.applications', self::CONNECTION);

        $this->assertNotEmpty($indexes);
        $this->assertTrue(
            collect($indexes)->contains(fn ($i) => $i['is_primary'] && in_array('id', $i['columns'], true))
        );
    }

    #[Test]
    public function it_lists_user_schemas_without_the_system_ones()
    {
        $schemas = $this->introspector()->getSchemas(self::CONNECTION);

        $this->assertContains('nq_alpha', $schemas);
        $this->assertNotContains('pg_catalog', $schemas);
        $this->assertNotContains('information_schema', $schemas);
    }
}
