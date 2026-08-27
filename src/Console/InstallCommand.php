<?php

namespace Jayanta\NaturalQuery\Console;

use Illuminate\Console\Command;

/**
 * Install Command
 *
 * Sets up NaturalQuery in a Laravel project:
 * 1. Publishes config file
 * 2. Publishes migrations
 * 3. Creates schema directory
 * 4. Copies example schema
 * 5. Runs migrations (optional)
 */
class InstallCommand extends Command
{
    protected $signature = 'naturalquery:install
                            {--migrate : Run migrations after install}
                            {--force : Overwrite existing files}';

    protected $description = 'Install NaturalQuery - publish config, migrations, and create schema directory';

    public function handle(): int
    {
        $this->info('Installing NaturalQuery...');
        $this->newLine();

        // Step 1: Publish config
        $this->comment('Publishing configuration...');
        $this->callSilently('vendor:publish', [
            '--tag' => 'naturalquery-config',
            '--force' => $this->option('force'),
        ]);
        $this->line('  ✓ Config published to config/naturalquery.php');

        // Step 2: Publish migrations
        $this->comment('Publishing migrations...');
        $this->callSilently('vendor:publish', [
            '--tag' => 'naturalquery-migrations',
            '--force' => $this->option('force'),
        ]);
        $this->line('  ✓ Migrations published');

        // Step 3: Create schema directory
        $schemaPath = config('naturalquery.schema.config_path', config_path('naturalquery-schemas'));

        if (!is_dir($schemaPath)) {
            mkdir($schemaPath, 0755, true);
            $this->line("  ✓ Schema directory created: {$schemaPath}");
        } else {
            $this->line("  - Schema directory already exists: {$schemaPath}");
        }

        // Step 4: Copy example schema
        $exampleDest = $schemaPath . '/example.php';
        $exampleSrc = dirname(__DIR__, 2) . '/stubs/schema-example.php';

        if (!file_exists($exampleDest) || $this->option('force')) {
            if (file_exists($exampleSrc)) {
                copy($exampleSrc, $exampleDest);
                $this->line('  ✓ Example schema created: config/naturalquery-schemas/example.php');
            }
        } else {
            $this->line('  - Example schema already exists (use --force to overwrite)');
        }

        // Step 5: Run migrations (optional)
        if ($this->option('migrate')) {
            $this->comment('Running migrations...');
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('NaturalQuery installed successfully!');
        $this->newLine();
        // Step 1 named GEMINI_API_KEY, which is the first thing every new
        // adopter reads and quietly made one vendor the default for everyone.
        // The package works the same on a model running on your own machine,
        // and an installer that mentions only a hosted API is choosing for
        // people who have not been told there is a choice.
        $this->line('Next steps:');
        $this->line('  1. Choose an LLM in .env -  a hosted API or one you run yourself:');
        $this->line('       NATURALQUERY_LLM_DRIVER=ollama      (local, no API key)');
        $this->line('       NATURALQUERY_LLM_DRIVER=gemini      GEMINI_API_KEY=…');
        $this->line('       NATURALQUERY_LLM_DRIVER=openai      OPENAI_API_KEY=…');
        $this->line('       NATURALQUERY_LLM_DRIVER=claude      ANTHROPIC_API_KEY=…');
        $this->line('     Any OpenAI-compatible service (DeepSeek, Groq, vLLM, LM Studio,');
        $this->line('     LocalAI, llama.cpp) works by adding an llm.providers block.');
        $this->line('  2. Run migrations: php artisan migrate');
        $this->line('  3. Generate a schema from your database: php artisan naturalquery:discover');
        $this->line('  4. Check everything is wired up: php artisan naturalquery:doctor');
        $this->line('  5. Try it: NaturalQuery::query("show me the top 10 items")');
        $this->newLine();
        $this->line('  Stuck at any point? php artisan naturalquery:doctor tells you');
        $this->line('  what is wrong and exactly how to fix it.');

        return self::SUCCESS;
    }
}
