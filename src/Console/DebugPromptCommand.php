<?php

namespace Jayanta\NaturalQuery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Jayanta\NaturalQuery\Engine\PromptBuilder;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;

/**
 * Debug Prompt Command
 *
 * Shows developers EXACTLY what prompt the AI receives for a given query.
 * Essential for tuning schema configs and reducing errors.
 *
 * Usage:
 *   php artisan naturalquery:debug "your query here"
 *   php artisan naturalquery:debug "your query" --scheme=orders
 *   php artisan naturalquery:debug "your query" --execute
 */
class DebugPromptCommand extends Command
{
    protected $signature = 'naturalquery:debug
                            {query : The natural language query to debug}
                            {--scheme= : Force a specific scheme}
                            {--execute : Actually execute the query and show results}
                            {--raw : Show the full raw AI response}';

    protected $description = 'Show the exact prompt sent to AI for a query — essential for debugging and tuning';

    public function handle(
        PromptBuilder $promptBuilder,
        SchemaRegistry $registry,
        LlmProviderInterface $llm
    ): int {
        $query = $this->argument('query');
        $scheme = $this->option('scheme');

        $this->info("NaturalQuery Prompt Debugger");
        $this->newLine();

        // Show config
        $this->comment("Configuration:");
        $this->line("  Query mode: " . config('naturalquery.query_mode', 'auto'));
        $this->line("  LLM provider: " . $llm->getName());
        $this->line("  Default scheme: " . (config('naturalquery.default_scheme') ?: 'none'));
        $this->line("  System instructions: " . (config('naturalquery.system_instructions') ? 'set (' . strlen(config('naturalquery.system_instructions')) . ' chars)' : 'none'));
        $this->line("  Schemas loaded: " . count($registry->all()) . ' (' . implode(', ', $registry->keys()) . ')');
        $this->newLine();

        // Detect scheme
        if (!$scheme) {
            $scheme = config('naturalquery.default_scheme');
        }

        if (!$scheme) {
            // Try keyword detection
            $queryLower = strtolower($query);
            foreach ($registry->all() as $key => $schemaData) {
                if (str_contains($queryLower, strtolower($key))) {
                    $scheme = $key;
                    break;
                }
                foreach ($schemaData['aliases'] ?? [] as $alias) {
                    if (str_contains($queryLower, strtolower($alias))) {
                        $scheme = $key;
                        break 2;
                    }
                }
            }
        }

        $this->comment("Scheme detection:");
        $this->line("  Detected: " . ($scheme ?: 'NONE — will use multi-scheme prompt'));
        $this->newLine();

        // Build prompt
        if ($scheme && $registry->has($scheme)) {
            $prompt = $promptBuilder->buildSqlPrompt($scheme, $query);
            $this->comment("Prompt type: Single-scheme ({$scheme})");
        } else {
            $prompt = $promptBuilder->buildMultiSchemePrompt($query);
            $this->comment("Prompt type: Multi-scheme (all schemas)");
        }

        $this->line("  Length: " . strlen($prompt) . " chars (" . str_word_count($prompt) . " words)");
        $this->newLine();

        // Show prompt
        $this->comment("=== FULL PROMPT ===");
        $this->line($prompt);
        $this->comment("=== END PROMPT ===");
        $this->newLine();

        // Execute if requested
        if ($this->option('execute')) {
            $this->comment("Sending to AI...");
            $response = $llm->generateSql($prompt);

            $this->newLine();
            $this->comment("=== AI RESPONSE ===");

            if ($response['success']) {
                $data = $response['data'];
                if (isset($data['sql'])) {
                    $this->info("SQL: " . $data['sql']);
                    $this->line("Scheme: " . ($data['scheme'] ?? '?'));
                    $this->line("Type: " . ($data['query_type'] ?? '?'));
                    $this->line("Explanation: " . ($data['explanation'] ?? '?'));
                } elseif (isset($data['error'])) {
                    $this->error("AI returned error: " . $data['error']);
                } else {
                    $this->warn("Unexpected response format");
                }

                if ($this->option('raw')) {
                    $this->newLine();
                    $this->line(json_encode($data, JSON_PRETTY_PRINT));
                }

                // Try executing the SQL
                if (isset($data['sql'])) {
                    $this->newLine();
                    $this->comment("Executing SQL...");
                    $connection = $scheme ? $registry->getConnection($scheme) : null;

                    try {
                        $rows = $connection
                            ? DB::connection($connection)->select($data['sql'])
                            : DB::select($data['sql']);

                        $this->info("Results: " . count($rows) . " rows");
                        foreach (array_slice($rows, 0, 5) as $row) {
                            $this->line("  " . json_encode((array) $row));
                        }
                        if (count($rows) > 5) {
                            $this->line("  ... and " . (count($rows) - 5) . " more");
                        }
                    } catch (\Exception $e) {
                        $this->error("SQL execution failed: " . $e->getMessage());
                    }
                }
            } else {
                $this->error("AI call failed: " . ($response['error'] ?? 'unknown'));
            }
        }

        return self::SUCCESS;
    }
}
