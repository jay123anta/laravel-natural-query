<?php

namespace Jayanta\NaturalQuery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Schema\IntrospectorRegistry;
use Jayanta\NaturalQuery\Security\InputGuard;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;

/**
 * Doctor Command — diagnose a NaturalQuery installation.
 *
 * Answers the question a stuck user actually has: "why isn't this working?"
 * Every check that can fail prints a concrete fix, because the raw symptom is
 * rarely the cause — a missing CA bundle surfaces as a generic cURL error, a
 * retired model as a 404, a typo'd table name as an empty result.
 *
 * Safe to run in any environment: read-only, and it never prints secrets.
 */
class DoctorCommand extends Command
{
    protected $signature = 'naturalquery:doctor
                            {--skip-api : Skip the live AI provider check (no API call, no quota used)}';

    protected $description = 'Diagnose your NaturalQuery setup and explain how to fix any problems';

    /** @var array<int, array{title: string, fix: string}> */
    protected array $problems = [];

    /** @var array<int, array{title: string, fix: string}> */
    protected array $warnings = [];

    public function handle(SchemaRegistry $registry): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>NaturalQuery Doctor</>');
        $this->line('  <fg=gray>Read-only checkup. No data is sent anywhere except the provider ping.</>');

        $this->checkConfiguration();
        $this->checkProvider();
        $this->checkDatabase();
        $this->checkSchemas($registry);
        $this->checkRoutes();
        $this->checkOptionalAiGuard();

        return $this->summarize();
    }

    // =====================================================================
    // Checks
    // =====================================================================

    protected function checkConfiguration(): void
    {
        $this->section('Configuration');

        if (file_exists(config_path('naturalquery.php'))) {
            $this->pass('Config published (config/naturalquery.php)');
        } else {
            $this->warn_('Config not published — using package defaults', 'php artisan naturalquery:install');
        }

        $driver = config('naturalquery.llm.driver');
        if (!$driver) {
            $this->problem('No LLM driver configured', 'Set NATURALQUERY_LLM_DRIVER in .env (e.g. gemini, openai, claude, ollama)');
            return;
        }

        $providers = config('naturalquery.llm.providers', []);
        if (!isset($providers[$driver])) {
            $available = implode(', ', array_keys($providers));
            $this->problem(
                "Driver '{$driver}' has no provider block",
                "Add a 'llm.providers.{$driver}' block in config/naturalquery.php, or switch NATURALQUERY_LLM_DRIVER to one of: {$available}"
            );
            return;
        }

        $this->pass("LLM driver: {$driver}");

        $providerConfig = $providers[$driver];
        $needsKey = !in_array($driver, ['ollama'], true)
            && !str_contains((string) ($providerConfig['base_url'] ?? ''), 'localhost')
            && !str_contains((string) ($providerConfig['base_url'] ?? ''), '127.0.0.1');

        if (empty($providerConfig['api_key'])) {
            $envVar = strtoupper($driver) . '_API_KEY';
            if ($needsKey) {
                $this->problem('API key is empty', "Set {$envVar} in .env, then run: php artisan config:clear");
            } else {
                $this->pass('No API key needed (local provider)');
            }
        } else {
            // Never print the key — only confirm its presence and shape.
            $this->pass('API key is set (' . strlen((string) $providerConfig['api_key']) . ' chars)');
        }

        if (!empty($providerConfig['model'])) {
            $this->pass('Model: ' . $providerConfig['model']);
        }

        $this->checkSslSetting();
    }

    /**
     * The optional ai-guard companion, if it is installed at all.
     *
     * Silent about it when absent — it is genuinely optional and the built-in
     * guard covers the same ground. The case worth reporting is when it IS
     * installed but cannot actually be used, because then the user believes
     * they have a layer they do not have.
     */
    protected function checkOptionalAiGuard(): void
    {
        /** @var InputGuard $guard */
        $guard = app(InputGuard::class);

        if (!$guard->hasAiGuard()) {
            return;
        }

        $this->section('ai-guard (optional companion)');

        if (!$guard->aiGuardSupportsTextScan()) {
            $this->warn_(
                'ai-guard is installed but this version cannot scan text — it has no detectText()',
                'Upgrade jayanta/laravel-ai-guard (v2.0.0 only exposes detect(Request)). '
                . 'Until then it is skipped entirely and the built-in InputGuard does the work, '
                . 'so nothing is unprotected — but ai-guard is contributing nothing.'
            );

            return;
        }

        $mode = (string) config('ai-guard.mode', 'log_only');
        $threshold = (int) config('ai-guard.confidence_threshold', 70);
        $enforce = config('naturalquery.privacy.ai_guard.enforce', 'auto');

        $this->pass("Installed and scanning (mode: {$mode}, threshold: {$threshold})");

        if ($enforce !== 'always' && $mode !== 'block') {
            $this->skip(
                "Detections are logged, not blocked — ai-guard is in '{$mode}' mode. "
                . "Set its mode to 'block', or privacy.ai_guard.enforce to 'always', to refuse them."
            );
        }
    }

    protected function checkSslSetting(): void
    {
        $verify = config('naturalquery.ssl_verify', true);

        if ($verify === false || (is_string($verify) && in_array(strtolower(trim($verify)), ['0', 'false', 'off', 'no'], true))) {
            $this->warn_(
                'SSL verification is DISABLED',
                'This exposes AI traffic to man-in-the-middle attacks. Download https://curl.se/ca/cacert.pem and set NATURALQUERY_SSL_VERIFY to its full path instead of false.'
            );
            return;
        }

        if (is_string($verify) && !in_array(strtolower(trim($verify)), ['1', 'true', 'on', 'yes', ''], true)) {
            if (is_file($verify)) {
                $this->pass('SSL verified against CA bundle: ' . $verify);
            } else {
                $this->problem(
                    'CA bundle not found: ' . $verify,
                    'Fix the NATURALQUERY_SSL_VERIFY path, or download a bundle from https://curl.se/ca/cacert.pem'
                );
            }
            return;
        }

        $this->pass('SSL verification enabled (system CA bundle)');
    }

    protected function checkProvider(): void
    {
        $this->section('AI provider');

        if ($this->option('skip-api')) {
            $this->skip('Live provider check skipped (--skip-api)');
            return;
        }

        try {
            $provider = app(LlmProviderInterface::class);
        } catch (\Throwable $e) {
            $this->problem('Could not build the LLM provider: ' . $e->getMessage(), 'Check the llm.driver and llm.providers config.');
            return;
        }

        try {
            $health = $provider->healthCheck();
        } catch (\Throwable $e) {
            $this->diagnoseProviderError($e->getMessage());
            return;
        }

        if (($health['status'] ?? null) === 'ok') {
            $this->pass('Provider reachable — ' . ($health['model'] ?? 'model') . ' is live');
            $this->pass('Voice input: ' . ($provider->supportsVoice() ? 'supported server-side' : 'browser only (provider has no audio API)'));
            return;
        }

        $this->diagnoseProviderError((string) ($health['message'] ?? 'unknown error'));
    }

    /**
     * Turn a raw provider error into the fix it actually needs.
     */
    protected function diagnoseProviderError(string $message): void
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'certificate') || str_contains($lower, 'curl error 60') || str_contains($lower, 'ssl')) {
            $this->problem(
                'TLS failure talking to the provider',
                'Your PHP has no CA certificate store (common on XAMPP/WAMP). Download https://curl.se/ca/cacert.pem, then set NATURALQUERY_SSL_VERIFY to its full path in .env and run: php artisan config:clear'
            );
            return;
        }

        if (str_contains($lower, '404')) {
            $this->problem(
                'Provider returned 404 — the configured model does not exist',
                'Models get retired (gemini-2.0-flash was). Check your provider\'s current model list and update the model in .env, then run: php artisan config:clear'
            );
            return;
        }

        if (str_contains($lower, '401') || str_contains($lower, '403') || str_contains($lower, 'api key')) {
            $this->problem(
                'Provider rejected the API key',
                'Verify the key is correct, active, and has access to this model. After editing .env run: php artisan config:clear'
            );
            return;
        }

        if (str_contains($lower, '429')) {
            $this->warn_(
                'Provider is rate limiting (429)',
                'Free-tier keys have low per-minute quotas. Wait a minute, keep the query cache enabled so repeats skip the API, or upgrade the key.'
            );
            return;
        }

        if (str_contains($lower, 'could not resolve') || str_contains($lower, 'connection') || str_contains($lower, 'timed out')) {
            $this->problem(
                'Cannot reach the provider',
                'Check network access and any proxy/firewall between this server and the provider. For self-hosted models, confirm base_url is correct and the server is running.'
            );
            return;
        }

        $this->problem('Provider health check failed: ' . $message, 'Run with -v for more detail, or check storage/logs/laravel.log.');
    }

    protected function checkDatabase(): void
    {
        $this->section('Database');

        // Check the connection NaturalQuery actually uses, which is not
        // necessarily the app default — sql.database_connection can point it
        // at a different one.
        $configured = config('naturalquery.sql.database_connection');

        try {
            $connection = DB::connection($configured);
            $connection->getPdo();
            $this->pass(
                'Connected (' . $connection->getDriverName() . ', database "' . $connection->getDatabaseName() . '"'
                . ($configured ? ', connection "' . $configured . '"' : '') . ')'
            );
        } catch (\Throwable $e) {
            $this->problem(
                'Cannot connect to the database: ' . $e->getMessage(),
                'Check DB_* settings in .env. The database must exist before migrating.'
            );
            return;
        }

        // Connecting is not the same as being usable. The engine introspects
        // the schema, which only Postgres and MySQL/MariaDB support — and
        // Laravel 11+ defaults to SQLite, so reporting a healthy connection
        // here while every query 500s would be the exact "confidently wrong"
        // failure this command exists to prevent.
        $driver = $connection->getDriverName();

        if (!IntrospectorRegistry::supports($driver)) {
            $this->problem(
                "Driver '{$driver}' is connected but NaturalQuery cannot introspect it. "
                . 'Supported: ' . implode(', ', IntrospectorRegistry::supportedDrivers())
                . '. Every query and every package route will fail.',
                "Set DB_CONNECTION to a supported driver in .env, or point 'sql.database_connection' "
                . 'in config/naturalquery.php at a supported connection. To add a driver of your own, '
                . "map it to a class implementing SchemaIntrospectorInterface under 'sql.introspectors'."
            );
            // Deliberately no early return: the remaining checks still work and
            // the point of this command is to surface every problem in one pass.
        }

        if (config('naturalquery.cache.enabled', true)) {
            $table = config('naturalquery.cache.table_name', 'naturalquery_cache');
            $this->checkTable($table, 'Query cache table', 'php artisan migrate');
        } else {
            $this->skip('Query cache disabled — every question costs an API call');
        }

        if (config('naturalquery.feedback.enabled', false)) {
            $table = config('naturalquery.feedback.table_name', 'naturalquery_feedback');
            $this->checkTable($table, 'Feedback table', 'php artisan migrate');
        }
    }

    protected function checkTable(string $table, string $label, string $fix): void
    {
        try {
            if (Schema::hasTable($table)) {
                $this->pass("{$label} '{$table}' exists");
            } else {
                $this->problem("{$label} '{$table}' is missing", $fix);
            }
        } catch (\Throwable $e) {
            $this->warn_("Could not inspect '{$table}': " . $e->getMessage(), $fix);
        }
    }

    protected function checkSchemas(SchemaRegistry $registry): void
    {
        $this->section('Schemas');

        $path = config('naturalquery.schema.config_path', config_path('naturalquery-schemas'));

        if (!is_dir($path)) {
            $this->problem(
                'Schema directory does not exist: ' . $path,
                'Run: php artisan naturalquery:install (or create the directory and add a schema file)'
            );
            return;
        }

        $schemas = $registry->all();

        if (empty($schemas)) {
            $this->problem(
                'No schema files found in ' . $path,
                'Generate one from your live database: php artisan naturalquery:discover'
            );
            return;
        }

        $this->pass(count($schemas) . ' schema(s) loaded: ' . implode(', ', array_keys($schemas)));

        $default = config('naturalquery.default_scheme');
        if ($default && !$registry->has($default)) {
            $this->problem(
                "default_scheme '{$default}' does not match any loaded schema",
                'Set default_scheme to one of: ' . implode(', ', array_keys($schemas))
            );
        } elseif (!$default && count($schemas) === 1) {
            $this->warn_(
                'Single schema but no default_scheme set',
                "Set 'default_scheme' => '" . array_key_first($schemas) . "' in config/naturalquery.php so users never have to name the dataset."
            );
        }

        $this->checkRelationshipTargets($registry);
        $this->checkCompetingMeasures($registry);

        foreach ($schemas as $key => $schema) {
            $this->checkSchemaAgainstDatabase($registry, $key);
        }
    }

    /**
     * More than one table can answer "revenue", and nothing says which one.
     *
     * Measured, not guessed: on a fourteen-table schema with both
     * order_items.line_total and payments.amount, the model chose payments.
     * Customers who had not paid vanished from the ranking and a region came
     * back at half its real revenue — a confident number from a defensible
     * join path, answering a different question.
     *
     * No amount of introspection can resolve this. The column names, types and
     * foreign keys are identical in kind; only the business knows which one it
     * means. Adding that in system_instructions took three sentences and moved
     * accuracy from 79% to 86%, with every multi-table question passing. So the
     * one thing worth saying here is: you have this choice, and nobody has made
     * it yet.
     */
    protected function checkCompetingMeasures(SchemaRegistry $registry): void
    {
        if (trim((string) config('naturalquery.system_instructions', '')) !== '') {
            return; // The choice has been made somewhere.
        }

        $withMeasures = [];

        foreach ($registry->all() as $key => $schema) {
            foreach ($schema['tables']['primary']['columns'] ?? [] as $column) {
                if (!empty($column['aggregatable'])) {
                    $withMeasures[] = $key;
                    continue 2;
                }
            }
        }

        if (count($withMeasures) < 2) {
            return;
        }

        sort($withMeasures);

        $this->warn_(
            count($withMeasures) . ' datasets have their own measures: ' . implode(', ', $withMeasures),
            'A question like "total revenue" can be answered from any of them, and nothing says which is '
                . 'authoritative — so the model picks, and a wrong pick returns a plausible number for a '
                . 'different question. Say which one you mean in \'system_instructions\' in '
                . 'config/naturalquery.php, e.g. "Revenue means SUM(order_items.line_total), never '
                . 'payments.amount." This is the single highest-value thing you can write.'
        );
    }

    /**
     * A foreign key pointing at a table nobody described is a join the package
     * will not offer, because generated SQL is validated against the tables
     * your schema files declare. That is the right call, but silently doing it
     * looks like the AI is simply bad at joins — so say it out loud.
     */
    protected function checkRelationshipTargets(SchemaRegistry $registry): void
    {
        $missing = $registry->undescribedRelationshipTargets();

        if (empty($missing)) {
            return;
        }

        $tables = array_unique(array_merge(...array_values($missing)));
        sort($tables);

        $discover = implode(' ', array_map(
            fn ($t) => '--table=' . (str_contains($t, '.') ? substr(strrchr($t, '.'), 1) : $t),
            $tables
        ));

        $this->warn_(
            'Foreign keys point at tables with no schema file: ' . implode(', ', $tables),
            'Questions needing those tables cannot be answered, and no join to them will be suggested. '
                . "To include them: php artisan naturalquery:discover {$discover} --merge"
        );
    }

    /**
     * Verify a schema file actually matches the live database. A typo here is
     * the most common cause of "it returns nothing" — and the AI can't tell
     * you, because it never sees the database.
     */
    protected function checkSchemaAgainstDatabase(SchemaRegistry $registry, string $key): void
    {
        $table = $registry->getTableName($key);

        if (!$table) {
            $this->problem("Schema '{$key}' has no table name", "Add tables.primary.name to the '{$key}' schema file.");
            return;
        }

        try {
            $connectionName = $registry->getConnection($key);
            $schemaBuilder = Schema::connection($connectionName);

            // A schema-qualified name (public.orders) may need the bare form
            // depending on driver and search_path. Laravel 11+ parses the
            // "schema.table" form and can throw when that schema is unknown to
            // the driver, so each form is probed independently — one throwing
            // must not abort the whole check and leave the schema unverified.
            $bare = str_contains($table, '.') ? substr(strrchr($table, '.'), 1) : $table;
            $exists = $this->tableExists($schemaBuilder, $table)
                || $this->tableExists($schemaBuilder, $bare);

            if (!$exists) {
                $this->problem(
                    "Schema '{$key}': table '{$table}' not found in the database",
                    'Fix the table name in the schema file, run your migrations, or regenerate with: php artisan naturalquery:discover'
                );
                return;
            }

            $actual = array_map('strtolower', $schemaBuilder->getColumnListing($bare));
            $declared = array_keys($registry->getColumns($key));
            $missing = array_values(array_filter(
                $declared,
                fn($column) => !in_array(strtolower($column), $actual, true)
            ));

            if ($missing) {
                $this->problem(
                    "Schema '{$key}': column(s) not in '{$table}': " . implode(', ', $missing),
                    'Correct these names in the schema file — the AI is told they exist, so queries using them will fail at execution.'
                );
                return;
            }

            $groupColumn = $registry->getGroupColumn($key);
            if ($groupColumn && !in_array(strtolower($groupColumn), $actual, true)) {
                $this->problem(
                    "Schema '{$key}': group_column '{$groupColumn}' is not a real column",
                    'Set group_column to the column results should be grouped/labelled by.'
                );
                return;
            }

            $this->pass("Schema '{$key}' → {$table} (" . count($declared) . ' columns verified)');
        } catch (\Throwable $e) {
            $this->warn_("Schema '{$key}': could not verify against the database — " . $e->getMessage(), 'Check the connection setting in this schema file.');
        }
    }

    /**
     * Does this table exist, as far as this naming form is concerned?
     *
     * Deliberately swallows driver errors rather than letting them bubble:
     * a qualified name that the driver cannot parse means "not found by this
     * form", not "verification impossible". Letting it escape would turn a
     * real, reportable problem into a warning and leave the command exiting 0
     * on a schema that does not match the database.
     */
    protected function tableExists($schemaBuilder, string $name): bool
    {
        try {
            return $schemaBuilder->hasTable($name);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function checkRoutes(): void
    {
        $this->section('HTTP endpoints');

        if (!config('naturalquery.routes.enabled', true)) {
            $this->skip('Routes disabled — the widget and API endpoints are unavailable');
            return;
        }

        $prefix = trim((string) config('naturalquery.routes.prefix', 'naturalquery'), '/');
        $this->pass("Routes registered under /{$prefix} (widget.js, text, voice, health)");

        $middleware = (array) config('naturalquery.routes.middleware', []);
        $hasAuth = (bool) array_filter($middleware, fn($m) => is_string($m) && str_starts_with($m, 'auth'));
        $hasThrottle = (bool) array_filter($middleware, fn($m) => is_string($m) && str_starts_with($m, 'throttle'));

        if (!$hasAuth && !$hasThrottle) {
            $this->warn_(
                'Endpoints are public and unthrottled',
                "Add 'throttle:60,1' (and auth middleware for private data) to routes.middleware in config/naturalquery.php."
            );
        } elseif (!$hasAuth) {
            // Throttling bounds the rate, not the spend, and without auth the
            // daily ceiling falls back to counting by IP — which is the weakest
            // form of it, on the one configuration where it matters most.
            $perDay = config('naturalquery.limits.queries_per_day');

            if ($perDay && (int) $perDay > 0) {
                $this->warn_(
                    "Endpoints are public (no auth). Daily ceiling: {$perDay} questions per IP",
                    'Every question spends your API key, and an IP is easy to change. Fine for a demo on '
                        . 'non-sensitive data; add auth middleware in config/naturalquery.php before this '
                        . 'faces the open internet.'
                );
            } else {
                $this->problem(
                    'Endpoints are public AND have no daily limit',
                    "Anyone who finds the URL can spend your API key without bound. Set "
                        . "'limits.queries_per_day' in config/naturalquery.php, add auth middleware, or both."
                );
            }
        } else {
            $this->pass('Protected by auth middleware');

            // Laravel's auth middleware redirects guests to a route named
            // 'login'. A fresh app has no such route until a starter kit is
            // installed, so the first request to any endpoint here dies with
            // RouteNotFoundException — a 500 that says nothing about the real
            // cause. Cheap to detect, baffling to debug.
            if (!\Illuminate\Support\Facades\Route::has('login')) {
                $this->warn_(
                    "auth middleware is on, but this app has no route named 'login'",
                    'Any unauthenticated request will fail with "Route [login] not defined" rather than a redirect. '
                    . "Install an auth starter kit, or drop 'auth' from routes.middleware in config/naturalquery.php "
                    . 'if these endpoints should be reachable without logging in.'
                );
            }
        }
    }

    // =====================================================================
    // Output helpers
    // =====================================================================

    protected function section(string $title): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
    }

    protected function pass(string $message): void
    {
        $this->line("    <fg=green>✓</> {$message}");
    }

    protected function skip(string $message): void
    {
        $this->line("    <fg=gray>-</> {$message}");
    }

    protected function problem(string $message, string $fix): void
    {
        $this->line("    <fg=red>✗</> {$message}");
        $this->line("      <fg=yellow>→ Fix:</> {$fix}");
        $this->problems[] = ['title' => $message, 'fix' => $fix];
    }

    /** Named with a trailing underscore to avoid clashing with Command::warn(). */
    protected function warn_(string $message, string $fix): void
    {
        $this->line("    <fg=yellow>!</> {$message}");
        $this->line("      <fg=yellow>→</> {$fix}");
        $this->warnings[] = ['title' => $message, 'fix' => $fix];
    }

    protected function summarize(): int
    {
        $this->newLine();

        $problems = count($this->problems);
        $warnings = count($this->warnings);

        if ($problems === 0 && $warnings === 0) {
            $this->line('  <fg=green;options=bold>All checks passed.</> Ask something: <fg=cyan>php artisan naturalquery:debug "top 10 by revenue"</>');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($problems === 0) {
            $this->line("  <fg=yellow;options=bold>{$warnings} warning(s)</>, nothing broken. Queries should work.");
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line("  <fg=red;options=bold>{$problems} problem(s) found</>" . ($warnings ? " and {$warnings} warning(s)" : '') . '. Fix the ✗ items above, then re-run this command.');
        $this->newLine();

        return self::FAILURE;
    }
}
