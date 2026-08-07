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

        // The suite runs on in-memory SQLite, which is now a supported driver —
        // so these cases exercise the real SqliteIntrospector rather than a
        // stand-in, and doctor reports on it exactly as it would in an app.
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

    /**
     * Discovering a subset of tables leaves foreign keys pointing at tables
     * with no schema file. Those joins are deliberately never offered — the
     * validator would reject the SQL — but a user seeing only the symptom
     * concludes the AI cannot do joins. Doctor names the cause instead.
     */
    #[Test]
    public function it_flags_foreign_keys_pointing_at_tables_that_were_never_described()
    {
        config(['naturalquery.schema.config_path' => __DIR__ . '/../Stubs/subset-schemas']);
        $this->app->forgetInstance(\Jayanta\NaturalQuery\Schema\SchemaRegistry::class);

        Schema::create('sub_orders', function ($table) {
            $table->id();
            $table->integer('customer_id');
            $table->integer('quantity');
            $table->string('status');
        });

        // Captured rather than expectsOutputToContain(): that helper matches
        // one expectation per write, so asserting on both the warning and its
        // fix line is order-sensitive and brittle.
        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('no schema file', $output);
        $this->assertStringContainsString('sub_customers', $output);
        $this->assertStringContainsString(
            'naturalquery:discover --table=sub_customers --merge',
            $output,
            'the warning must print the exact command that fixes it'
        );
    }

    #[Test]
    public function it_stays_quiet_when_every_related_table_has_a_schema_file()
    {
        $this->createStubTables();

        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('no schema file', Artisan::output());
    }

    /**
     * Measured on a fourteen-table schema: with both order_items.line_total
     * and payments.amount available, the model answered "revenue" from
     * payments, so unpaid customers vanished and a region came back at half
     * its real revenue. Three sentences of system_instructions moved accuracy
     * from 79% to 86% and fixed every multi-table question.
     *
     * The package cannot make that choice — the columns are identical in kind.
     * It can say the choice exists.
     */
    /** rel_orders and rel_sales each carry their own measures. */
    private function useCompetingMeasureSchemas(): void
    {
        config(['naturalquery.schema.config_path' => __DIR__ . '/../Stubs/related-schemas']);
        $this->app->forgetInstance(\Jayanta\NaturalQuery\Schema\SchemaRegistry::class);
    }

    #[Test]
    public function it_points_out_that_several_datasets_could_answer_the_same_question()
    {
        $this->useCompetingMeasureSchemas();
        config(['naturalquery.system_instructions' => '']);

        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('datasets have their own measures', $output);
        $this->assertStringContainsString('system_instructions', $output);
    }

    #[Test]
    public function it_stays_quiet_once_the_ambiguity_has_been_settled()
    {
        $this->useCompetingMeasureSchemas();
        config(['naturalquery.system_instructions' => 'Revenue means SUM(rel_orders.quantity * unit_price).']);

        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('datasets have their own measures', Artisan::output());
    }

    #[Test]
    public function a_single_dataset_with_measures_is_not_ambiguous()
    {
        // Nothing to choose between, so saying so would be noise.
        $this->createStubTables();
        config(['naturalquery.system_instructions' => '']);

        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('datasets have their own measures', Artisan::output());
    }

    /**
     * Without CORS the browser blocks the response before any JavaScript sees
     * it, so a correctly answered request surfaces as a network error with no
     * mention of policy. Cheap to detect, baffling to debug.
     */
    #[Test]
    public function it_flags_a_separate_front_end_with_no_cors_entry()
    {
        $this->createStubTables();
        config([
            'naturalquery.routes.middleware' => ['api', 'auth:sanctum'],
            'cors.paths' => ['api/*'],
        ]);

        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('not in cors.paths', $output);
        $this->assertStringContainsString('config/cors.php', $output);
    }

    #[Test]
    public function it_stays_quiet_once_cors_covers_the_prefix()
    {
        $this->createStubTables();
        config([
            'naturalquery.routes.middleware' => ['api', 'auth:sanctum'],
            'cors.paths' => ['api/*', 'naturalquery/*'],
        ]);

        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('not in cors.paths', Artisan::output());
    }

    #[Test]
    public function a_same_domain_blade_app_is_never_asked_about_cors()
    {
        // Session cookies on one origin need no CORS at all, and warning about
        // it there would be noise on the most common setup.
        $this->createStubTables();
        config(['naturalquery.routes.middleware' => ['web', 'auth'], 'cors.paths' => []]);

        Artisan::call('naturalquery:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('cors.paths', Artisan::output());
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

    /**
     * Verification on, but no CA store to verify against.
     *
     * This was a green tick regardless, with the truth only emerging from the
     * live provider check — so `--skip-api`, the fast check the docs recommend,
     * reported a healthy setup that could not reach a provider at all. It cost
     * this project an afternoon: a benchmark run scored 0/36 and read exactly
     * like a package regression, when PHP simply had no certificates.
     *
     * Common enough on XAMPP and WAMP to be the default experience there, and
     * Rule 0 says those are stacks the package has to work on.
     */
    #[Test]
    public function it_flags_ssl_verification_with_no_ca_store_behind_it()
    {
        $this->createStubTables();
        config(['naturalquery.ssl_verify' => true]);

        $this->swap(
            \Jayanta\NaturalQuery\Console\DoctorCommand::class,
            new class extends \Jayanta\NaturalQuery\Console\DoctorCommand {
                protected function phpCaBundle(): ?string { return null; }
                protected function bundleOnDisk(): ?string { return 'C:/xampp/apache/bin/curl-ca-bundle.crt'; }
            }
        );

        // Captured rather than expectsOutputToContain(): that helper matches
        // one expectation per write, so asserting on both the warning and its
        // fix line is order-sensitive and brittle.
        $exit = Artisan::call('naturalquery:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('no CA certificate store', $output);
        // It found one on disk, so the fix is a single line rather than a
        // download and a hunt for where to put it.
        $this->assertStringContainsString('curl-ca-bundle.crt', $output);
        $this->assertStringContainsString('NATURALQUERY_SSL_VERIFY', $output);
        // Nothing is broken yet — it may still work. A warning, not a fault.
        $this->assertSame(0, $exit);
    }

    #[Test]
    public function it_confirms_the_ca_store_php_will_actually_use()
    {
        $this->createStubTables();
        config(['naturalquery.ssl_verify' => true]);

        $this->swap(
            \Jayanta\NaturalQuery\Console\DoctorCommand::class,
            new class extends \Jayanta\NaturalQuery\Console\DoctorCommand {
                protected function phpCaBundle(): ?string { return '/etc/ssl/certs/ca-certificates.crt'; }
            }
        );

        $this->artisan('naturalquery:doctor --skip-api')
            ->expectsOutputToContain('ca-certificates.crt')
            ->assertExitCode(0);
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
     * A stock Laravel 11/12 app runs on SQLite, and the package used to report
     * "✓ Connected (sqlite …)" and exit 0 while every route died with
     * "Unsupported database driver". SQLite is supported now, so the healthy
     * path is the one to pin: the most common install must simply work.
     */
    #[Test]
    public function a_stock_sqlite_app_is_healthy()
    {
        $this->createStubTables();

        $this->artisan('naturalquery:doctor --skip-api')
            ->assertExitCode(0)
            ->expectsOutputToContain('Connected (sqlite');
    }

    /**
     * Connecting is not the same as being usable, and a checkup that says
     * "healthy" about a setup that cannot serve a single query is the worst
     * thing this command can do. Still true for a driver nobody has written an
     * introspector for.
     */
    #[Test]
    public function it_flags_a_driver_it_cannot_introspect_even_though_the_connection_works()
    {
        $this->createStubTables();

        // Point the package at a connection whose driver has no introspector.
        // The connection itself is SQLite so it genuinely opens — which is the
        // situation being tested: reachable, but not introspectable.
        config([
            'database.connections.nq_unsupported' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'naturalquery.sql.introspectors' => ['sqlite' => null],
        ]);

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

    /**
     * Found during a fresh-install run on Laravel 12: a new app has no route
     * named 'login' until a starter kit is added, but Laravel's auth
     * middleware redirects guests to exactly that. So the very first request
     * an adopter makes after installing dies with RouteNotFoundException — a
     * 500 naming neither the middleware nor the missing route — while doctor
     * happily reported "Protected by auth middleware".
     */
    #[Test]
    public function it_warns_when_auth_middleware_is_on_but_the_app_has_no_login_route()
    {
        $this->createStubTables();
        config(['naturalquery.routes.middleware' => ['web', 'auth', 'throttle:60,1']]);

        $this->artisan('naturalquery:doctor --skip-api')
            ->expectsOutputToContain("no route named 'login'");
    }
}
