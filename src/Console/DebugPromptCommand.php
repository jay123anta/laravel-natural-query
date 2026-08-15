<?php

namespace Jayanta\NaturalQuery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Jayanta\NaturalQuery\Engine\DatasetSeeder;
use Jayanta\NaturalQuery\Engine\PromptBudget;
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
 *   php artisan naturalquery:debug "your query" --dataset=orders
 *   php artisan naturalquery:debug "your query" --execute
 */
class DebugPromptCommand extends Command
{
    protected $signature = 'naturalquery:debug
                            {query : The natural language query to debug}
                            {--dataset= : Force a specific dataset}
                            {--execute : Actually execute the query and show results}
                            {--raw : Show the full raw AI response}';

    protected $description = 'Show the exact prompt sent to AI for a query — essential for debugging and tuning';

    public function handle(
        PromptBuilder $promptBuilder,
        SchemaRegistry $registry,
        LlmProviderInterface $llm,
        DatasetSeeder $seeder,
        PromptBudget $budget
    ): int {
        $query = $this->argument('query');
        $dataset = $this->option('dataset');

        $this->info("NaturalQuery Prompt Debugger");
        $this->newLine();

        // Show config
        $this->comment("Configuration:");
        $this->line("  Query mode: " . config('naturalquery.query_mode', 'auto'));
        $this->line("  LLM provider: " . $llm->getName());
        $this->line("  Default dataset: " . (config('naturalquery.default_dataset') ?: 'none'));
        $this->line("  System instructions: " . (config('naturalquery.system_instructions') ? 'set (' . strlen(config('naturalquery.system_instructions')) . ' chars)' : 'none'));
        $this->line("  Schemas loaded: " . count($registry->all()) . ' (' . implode(', ', $registry->keys()) . ')');
        $this->newLine();

        // Detect dataset
        if (!$dataset) {
            $dataset = config('naturalquery.default_dataset');
        }

        // DatasetSeeder, not a second implementation of it. This command's
        // entire value is that it decides what the engine decides; an
        // open-coded str_contains() loop over keys and aliases misses
        // query_routing rules and can land on a different dataset than the
        // real question would, which sends the person debugging off in the
        // wrong direction at exactly the moment they are relying on it.
        if (!$dataset) {
            $dataset = $seeder->detect($query);
        }

        $this->comment("Dataset detection:");
        $this->line("  Detected: " . ($dataset ?: 'NONE — will use multi-dataset prompt'));
        $this->newLine();

        // Same condition as QueryOrchestrator::processWithSqlGeneration(). The
        // linked-schemas half used to be missing here, so on any install whose
        // schemas declare relationships this printed the focused prompt while
        // the engine sent the multi-dataset one — a question can legitimately
        // span linked datasets, and only the multi-dataset prompt permits the
        // join that answers it.
        if ($dataset && $registry->has($dataset) && !$registry->hasLinkedSchemas()) {
            $prompt = $promptBuilder->buildSqlPrompt($dataset, $query);
            $datasetsRendered = 1;
            $this->comment("Prompt type: Single-dataset ({$dataset})");
        } else {
            $prompt = $promptBuilder->buildMultiDatasetPrompt($query);
            $datasetsRendered = count($registry->all());
            $this->comment("Prompt type: Multi-dataset (all datasets)");

            if ($dataset && $registry->has($dataset) && $registry->hasLinkedSchemas()) {
                $this->line("  (schemas are linked, so the engine sends the multi-dataset prompt");
                $this->line("   even though '{$dataset}' was detected — a question may span them)");
            }
        }

        $this->line("  Length: " . strlen($prompt) . " chars (" . str_word_count($prompt) . " words)");

        // prompts.max_chars refuses a prompt BEFORE it is sent. Printing one
        // that would never leave the machine, with no note saying so, is the
        // same class of mistake as printing the wrong prompt: what is on
        // screen is not what happens.
        if ($refusal = $budget->check($prompt, $datasetsRendered)) {
            $this->newLine();
            $this->error("This prompt would be REFUSED, not sent:");
            $this->line("  " . $refusal);
        }

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
                    $this->line("Dataset: " . ($data['dataset'] ?? '?'));
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
                    $connection = $dataset ? $registry->getConnection($dataset) : null;

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
