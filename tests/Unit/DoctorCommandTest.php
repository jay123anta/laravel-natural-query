<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The doctor is the first thing a stuck user runs. Its job is not to be
 * pretty — it is to name the real cause and print the exact fix.
 */
class DoctorCommandTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // Feedback is on by default and needs a published migration; individual
        // tests opt back in rather than every case tripping over it.
        $app['config']->set('naturalquery.feedback.enabled', false);

        // The suite runs on in-memory SQLite, which the package does not
        // support for real — doctor is supposed to flag it. Register a test
        // introspector for sqlite so these cases can exercise the
        // driver-independent checks. `it_flags_an_unsupported_database_driver`
        // deliberately removes this to assert the real behaviour.
        $app['config']->set(
            'naturalquery.sql.introspectors.sqlite',
            \Jayanta\NaturalQuery\Tests\Support\SqliteTestIntrospector::class
        );
    }

    /** Build the tables the stub schema declares, so schema checks pass. */
    private function createStubTables(): void
    {
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('amount', 12, 2);
            $table->string('status');
        });

        Schema::create('district_stats', function ($table) {
            $table->id();
            $table->string('district_name');
            $table->integer('total_households');
        });
    }

    #[Test]
    public function it_reports_a_healthy_setup_when_everything_lines_up()
    {
        $this->createStubTables();

        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(0)
            ->expectsOutputToContain('LLM driver: gemini')
            ->expectsOutputToContain('2 schema(s) loaded');
    }

    #[Test]
    public function it_flags_a_schema_whose_table_does_not_exist()
    {
        // No tables created — the stub schemas point at tables that aren't there.
        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('not found in the database')
            ->expectsOutputToContain('naturalquery:discover');
    }

    #[Test]
    public function it_flags_a_column_declared_in_the_schema_but_missing_from_the_table()
    {
        // 'status' is declared by the stub schema; omit it here.
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('amount', 12, 2);
        });
        Schema::create('district_stats', function ($table) {
            $table->id();
            $table->string('district_name');
            $table->integer('total_households');
        });

        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('status');
    }

    #[Test]
    public function it_flags_a_missing_api_key()
    {
        $this->createStubTables();
        config(['naturalquery.llm.providers.gemini.api_key' => '']);

        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('API key is empty')
            ->expectsOutputToContain('GEMINI_API_KEY');
    }

    #[Test]
    public function it_never_prints_the_api_key()
    {
        $this->createStubTables();
        config(['naturalquery.llm.providers.gemini.api_key' => 'AIzaSy-SUPER-SECRET-VALUE']);

        // Captured directly rather than via doesntExpectOutputToContain(),
        // which is not available across every supported Laravel version.
        $exitCode = Artisan::call('naturalquery:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('AIzaSy-SUPER-SECRET-VALUE', $output);
        $this->assertStringContainsString('API key is set', $output);
        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function it_flags_disabled_ssl_verification_as_a_security_warning()
    {
        $this->createStubTables();
        config(['naturalquery.ssl_verify' => false]);

        $this->artisan('naturalquery:doctor --skip-api')
            ->expectsOutputToContain('SSL verification is DISABLED')
            ->expectsOutputToContain('cacert.pem')
            // A warning must not fail the command — nothing is broken
            ->assertExitCode(0);
    }

    #[Test]
    public function it_flags_a_ca_bundle_path_that_does_not_exist()
    {
        $this->createStubTables();
        config(['naturalquery.ssl_verify' => '/no/such/path/cacert.pem']);

        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('CA bundle not found');
    }

    #[Test]
    public function it_flags_a_default_scheme_that_matches_no_schema()
    {
        $this->createStubTables();
        config(['naturalquery.default_scheme' => 'typo_scheme']);

        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain("default_scheme 'typo_scheme'");
    }

    #[Test]
    public function it_warns_when_endpoints_are_public_and_unthrottled()
    {
        $this->createStubTables();
        config(['naturalquery.routes.middleware' => ['web']]);

        $this->artisan('naturalquery:doctor --skip-api')
            ->expectsOutputToContain('public and unthrottled')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_flags_a_missing_cache_table_when_caching_is_enabled()
    {
        $this->createStubTables();
        config([
            'naturalquery.cache.enabled' => true,
            'naturalquery.cache.table_name' => 'naturalquery_cache',
        ]);

        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('php artisan migrate');
    }

    #[Test]
    public function it_flags_a_missing_feedback_table_when_feedback_is_enabled()
    {
        $this->createStubTables();
        config(['naturalquery.feedback.enabled' => true]);

        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain("Feedback table 'naturalquery_feedback' is missing");
    }

    #[Test]
    public function it_notes_when_the_query_cache_is_switched_off()
    {
        $this->createStubTables();
        config(['naturalquery.cache.enabled' => false]);

        // Not a failure, but worth surfacing: every question costs an API call,
        // which is what exhausts free-tier quotas.
        $this->artisan('naturalquery:doctor --skip-api')
            ->expectsOutputToContain('Query cache disabled')
            ->assertExitCode(0);
    }

    /**
     * Regression: doctor used to report "✓ Connected (sqlite …)" and exit 0 on
     * a stock Laravel 11/12 app, while every package route died with
     * "Unsupported database driver". Connecting is not the same as being
     * usable, and a checkup that says "healthy" about a setup that cannot serve
     * a single query is the worst thing this command can do.
     */
    #[Test]
    public function it_flags_an_unsupported_database_driver_even_though_the_connection_works()
    {
        $this->createStubTables();
        // Drop the test introspector: sqlite falls back to unsupported, as in
        // a real app, while the built-in pgsql/mysql/mariadb stay registered.
        config(['naturalquery.sql.introspectors' => []]);

        // One substring per output line: expectsOutputToContain sets up a
        // Mockery expectation per write, and two substrings on the same line
        // would leave the second unconsumed.
        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain("Driver 'sqlite' is connected but NaturalQuery cannot introspect it")
            ->expectsOutputToContain('sql.database_connection');
    }

    /**
     * The driver check must read the connection NaturalQuery is configured to
     * use, not blindly the app default — `sql.database_connection` can point
     * the package at a different one.
     */
    #[Test]
    public function it_checks_the_connection_naturalquery_is_configured_to_use()
    {
        $this->createStubTables();
        config(['naturalquery.sql.database_connection' => 'testing']);

        $this->artisan('naturalquery:doctor --skip-api')
            ->expectsOutputToContain('connection "testing"')
            ->assertExitCode(0);
    }
}
