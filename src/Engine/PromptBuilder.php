<?php

namespace Jayanta\NaturalQuery\Engine;

use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Contracts\SchemaIntrospectorInterface;
use Jayanta\NaturalQuery\Feedback\FeedbackStore;

/**
 * Prompt Builder
 *
 * Builds schema-aware prompts for LLM providers.
 * The AI receives FULL table structure — column names, types, descriptions,
 * aliases, JOINs, computed metrics, example queries, AND past corrections.
 *
 * This is how the package enables ANY project to work: the AI understands
 * the database structure from the schema config and generates correct SQL.
 *
 * PRIVACY: Only schema structure is included - never actual data values.
 */
class PromptBuilder
{
    protected SchemaRegistry $registry;
    protected SchemaIntrospectorInterface $introspector;
    protected FeedbackStore $feedback;

    public function __construct(SchemaRegistry $registry, SchemaIntrospectorInterface $introspector, FeedbackStore $feedback)
    {
        $this->registry = $registry;
        $this->introspector = $introspector;
        $this->feedback = $feedback;
    }

    /**
     * Build a complete SQL generation prompt for a specific scheme.
     *
     * Used in sql_generation mode — AI generates the full SQL query.
     */
    public function buildSqlPrompt(string $schemeKey, string $userQuery): string
    {
        $schema = $this->registry->get($schemeKey);
        if (!$schema) {
            return "Error: Unknown scheme '{$schemeKey}'";
        }

        $dialect = $this->introspector->getDialect();
        $schemaInfo = $this->buildFullSchemaInfo($schemeKey, $schema);
        $examples = $this->buildExamples($schemeKey);
        $llmInstructions = $this->registry->getLlmInstructions($schemeKey);
        $maxLimit = $this->registry->getMaxLimit($schemeKey) ?? config('naturalquery.sql.max_limit');
        $defaultLimit = $schema['defaults']['limit'] ?? config('naturalquery.sql.default_limit', 100);

        $limitRule = $maxLimit
            ? "Always include LIMIT (default {$defaultLimit}, max {$maxLimit})"
            : "Include LIMIT when appropriate (default {$defaultLimit})";

        // Project-level system instructions (custom per project)
        $systemInstructions = config('naturalquery.system_instructions', '');

        // Custom system role (if project overrides it)
        $systemRole = config('naturalquery.prompts.system_role')
            ?? "You are a SQL query generator for a {$dialect} database. Generate a SELECT query based on the user's natural language request.";

        $prompt = "{$systemRole}\n\n";

        if ($systemInstructions) {
            $prompt .= "PROJECT CONTEXT:\n{$systemInstructions}\n\n";
        }

        $prompt .= "DATABASE SCHEMA (these are the ONLY tables you can query):\n\n{$schemaInfo}\n";

        if ($llmInstructions) {
            $prompt .= "\nDATA CONTEXT:\n{$llmInstructions}\n";
        }

        $prompt .= <<<PROMPT

IMPORTANT RULES:
1. ONLY generate SELECT queries — no INSERT, UPDATE, DELETE, DROP, etc.
2. ONLY use tables listed above with their exact schema-qualified names.
3. ONLY use columns listed under each table — do NOT invent column names.
4. COMPUTED METRICS are NOT database columns! Use the SQL expression provided.
5. Use proper {$dialect} syntax.
6. {$limitRule}
7. For ranking queries, use ORDER BY with the appropriate column or expression.
8. For specific record lookup (e.g., a name), use smart matching:
   WHERE LOWER(column) = LOWER('value') OR LOWER(column) LIKE LOWER('%value%')
   ORDER BY CASE WHEN LOWER(column) = LOWER('value') THEN 0 ELSE 1 END
   LIMIT 1
   Never use ILIKE — it is PostgreSQL-only and a syntax error on MySQL.
9. If a JOIN is specified in the schema, you MUST include it in your query.
10. Column aliases tell you what users might call a column — match user words to the correct column.
PROMPT;

        if ($examples) {
            $prompt .= "\n\nEXAMPLE QUERIES (follow this pattern):\n{$examples}\n";
        }

        // Include past corrections so the AI learns from mistakes
        $corrections = $this->buildCorrections($schemeKey);
        if ($corrections) {
            $prompt .= "\n\nPAST CORRECTIONS (avoid these mistakes):\n{$corrections}\n";
        }

        $prompt .= <<<PROMPT

USER QUERY: "{$userQuery}"

Respond with ONLY a JSON object (no markdown):
{
    "sql": "SELECT ... FROM ... ORDER BY ... LIMIT ...",
    "scheme": "{$schemeKey}",
    "query_type": "ranking|district|aggregation|overview",
    "metric": "main metric column name or null",
    "district": "specific district/group name if mentioned, or null",
    "order": "DESC or ASC",
    "limit": {$defaultLimit},
    "explanation": "Brief explanation of what the query does"
}

If the query cannot be processed, respond with:
{
    "error": "Reason why",
    "needs_clarification": true,
    "clarification_type": "scheme|metric|ambiguous",
    "suggestions": ["suggestion 1", "suggestion 2"]
}

RESPOND WITH JSON ONLY:
PROMPT;

        return $prompt;
    }

    /**
     * Build a multi-scheme SQL generation prompt (scheme not yet identified).
     *
     * AI must first identify which dataset to query, then generate SQL.
     */
    public function buildMultiSchemePrompt(string $userQuery): string
    {
        $dialect = $this->introspector->getDialect();
        $allSchemaInfo = $this->buildAllSchemasFullInfo();
        $defaultLimit = config('naturalquery.sql.default_limit', 100);
        $maxLimit = config('naturalquery.sql.max_limit');
        $systemInstructions = config('naturalquery.system_instructions', '');
        $systemRole = config('naturalquery.prompts.system_role')
            ?? "You are a SQL query generator for a {$dialect} database. Generate a SELECT query based on the user's natural language request.";

        $limitRule = $maxLimit
            ? "Always include LIMIT (default {$defaultLimit}, max {$maxLimit})"
            : "Include LIMIT when appropriate (default {$defaultLimit})";

        $prompt = "{$systemRole}\n\n";

        if ($systemInstructions) {
            $prompt .= "PROJECT CONTEXT:\n{$systemInstructions}\n\n";
        }

        // Query routing hints
        $routingHints = $this->buildRoutingHints();
        if ($routingHints) {
            $prompt .= "QUERY ROUTING (use these to pick the correct dataset):\n{$routingHints}\n\n";
        }

        $prompt .= "AVAILABLE DATASETS (these are the ONLY tables you can query):\n\n{$allSchemaInfo}\n";

        $prompt .= <<<PROMPT

IMPORTANT RULES:
1. ONLY generate SELECT queries — no INSERT, UPDATE, DELETE, DROP, etc.
2. ONLY use tables listed above with their exact schema-qualified names.
3. ONLY use columns listed under each table.
4. COMPUTED METRICS are NOT database columns! Use the SQL expression.
5. Use proper {$dialect} syntax.
6. {$limitRule}
7. If a JOIN is specified for a dataset, ALWAYS include it.
8. Column aliases tell you what users might call a column — match user words.
9. For specific record lookup, match with LOWER(column) = LOWER('value'), falling
   back to LOWER(column) LIKE LOWER('%value%'). Never use ILIKE — it is
   PostgreSQL-only and a syntax error on MySQL.
PROMPT;

        // Global example queries (cross-schema)
        $globalExamples = $this->buildGlobalExamples();
        if ($globalExamples) {
            $prompt .= "\n\nGLOBAL EXAMPLES:\n{$globalExamples}\n";
        }

        // Include past corrections for all schemes
        $corrections = $this->buildAllCorrections();
        if ($corrections) {
            $prompt .= "\n\nPAST CORRECTIONS (avoid these mistakes):\n{$corrections}\n";
        }

        $prompt .= <<<PROMPT

USER QUERY: "{$userQuery}"

Return JSON only (no markdown):
{
    "sql": "SELECT ... FROM ... ORDER BY ... LIMIT ...",
    "scheme": "dataset_key",
    "query_type": "ranking|district|aggregation|overview",
    "metric": "main metric name or null",
    "district": "specific group name if mentioned, or null",
    "order": "DESC or ASC",
    "limit": {$defaultLimit},
    "explanation": "Brief explanation"
}

Or if unclear:
{
    "error": "Reason",
    "needs_clarification": true,
    "clarification_type": "scheme|metric|ambiguous",
    "suggestions": ["try asking about X", "try asking about Y"]
}

JSON ONLY:
PROMPT;

        return $prompt;
    }

    /**
     * Build FULL schema info for a single scheme — includes everything the AI needs.
     */
    protected function buildFullSchemaInfo(string $schemeKey, array $schema): string
    {
        $lines = [];
        $primary = $schema['tables']['primary'] ?? [];
        $tableName = $primary['name'] ?? 'unknown';
        $groupColumn = $primary['group_column'] ?? 'name';
        $joinClause = $primary['required_join'] ?? null;
        $selectOverride = $primary['select_override'] ?? null;

        $lines[] = "TABLE: {$tableName}";
        $lines[] = "  Dataset: {$schemeKey} ({$schema['name']})";
        $lines[] = "  Description: " . ($schema['description'] ?? '');
        $lines[] = "  Group/Filter Column: {$groupColumn}";

        if ($joinClause) {
            $lines[] = "  REQUIRED JOIN: {$joinClause}";
            $lines[] = "  Use \"{$selectOverride}\" in SELECT for the group column (alias it AS {$groupColumn})";
        }

        // Columns with full detail
        $columns = $primary['columns'] ?? [];
        $lines[] = "";
        $lines[] = "  COLUMNS (only use these — do not invent column names):";
        foreach ($columns as $colName => $colDef) {
            $desc = $colDef['description'] ?? '';
            $type = $colDef['type'] ?? '';
            $unit = isset($colDef['unit']) ? " [{$colDef['unit']}]" : '';
            $aliases = !empty($colDef['aliases']) ? ' (user might say: ' . implode(', ', $colDef['aliases']) . ')' : '';

            $flags = [];
            if ($colDef['filterable'] ?? false) $flags[] = 'filterable';
            if ($colDef['groupable'] ?? false) $flags[] = 'groupable';
            if ($colDef['aggregatable'] ?? false) $flags[] = 'aggregatable';
            if ($colDef['sortable'] ?? false) $flags[] = 'sortable';
            $flagStr = !empty($flags) ? ' [' . implode(', ', $flags) . ']' : '';

            $lines[] = "    - {$colName} ({$type}){$unit}: {$desc}{$aliases}{$flagStr}";
        }

        // Computed metrics
        $computed = $schema['computed_metrics'] ?? [];
        if (!empty($computed)) {
            $lines[] = "";
            $lines[] = "  COMPUTED METRICS (NOT database columns — use the SQL expression in SELECT/ORDER BY):";
            foreach ($computed as $metricKey => $metricData) {
                $expr = $metricData['expression'] ?? '';
                $desc = $metricData['description'] ?? '';
                $unit = isset($metricData['unit']) ? " [{$metricData['unit']}]" : '';
                $aliases = !empty($metricData['aliases']) ? ' (user might say: ' . implode(', ', $metricData['aliases']) . ')' : '';
                $lines[] = "    - {$metricKey}{$unit}: {$desc}{$aliases}";
                $lines[] = "      SQL expression: {$expr} AS {$metricKey}";
            }
        }

        // Required filter
        $requiredFilter = $primary['required_filter'] ?? null;
        if ($requiredFilter) {
            $lines[] = "";
            $lines[] = "  REQUIRED FILTER (always include in WHERE): {$requiredFilter}";
        }

        // Query patterns (SQL templates for different query types)
        $queryPatterns = $schema['query_patterns'] ?? [];
        if (!empty($queryPatterns)) {
            $lines[] = "";
            $lines[] = "  SQL QUERY PATTERNS (use these as templates):";
            foreach ($queryPatterns as $patternKey => $pattern) {
                $desc = $pattern['description'] ?? $patternKey;
                $sql = $pattern['sql'] ?? '';
                $lines[] = "    [{$patternKey}] {$desc}:";
                $lines[] = "      {$sql}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build full schema info for ALL schemes — used in multi-scheme prompts.
     */
    protected function buildAllSchemasFullInfo(): string
    {
        $sections = [];

        foreach ($this->registry->all() as $key => $schema) {
            $sections[] = $this->buildFullSchemaInfo($key, $schema);
            $sections[] = ""; // blank line between schemas
        }

        return implode("\n", $sections);
    }

    /**
     * Build example queries section.
     */
    protected function buildExamples(string $schemeKey): string
    {
        $examples = $this->registry->getExampleQueries($schemeKey);
        if (empty($examples)) {
            return '';
        }

        $lines = [];
        foreach ($examples as $ex) {
            $lines[] = "Q: {$ex['natural']}";
            $lines[] = "SQL: {$ex['sql']}";
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    /**
     * Build corrections section for a specific scheme.
     */
    protected function buildCorrections(string $schemeKey): string
    {
        $corrections = $this->feedback->getCorrectionsForPrompt($schemeKey);
        if (empty($corrections)) {
            return '';
        }

        $lines = [];
        foreach ($corrections as $c) {
            $lines[] = "- When user asked: \"{$c['query']}\"";
            $lines[] = "  Problem: {$c['correction']}";
            if (!empty($c['corrected_sql'])) {
                $lines[] = "  Correct SQL: {$c['corrected_sql']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build corrections section for all schemes.
     */
    protected function buildAllCorrections(): string
    {
        $corrections = $this->feedback->getAllCorrectionsForPrompt();
        if (empty($corrections)) {
            return '';
        }

        $lines = [];
        foreach ($corrections as $c) {
            $lines[] = "- [{$c['scheme']}] When user asked: \"{$c['query']}\"";
            $lines[] = "  Problem: {$c['correction']}";
            if (!empty($c['corrected_sql'])) {
                $lines[] = "  Correct SQL: {$c['corrected_sql']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build routing hints from config for multi-scheme prompts.
     */
    protected function buildRoutingHints(): string
    {
        $routing = config('naturalquery.query_routing', []);
        if (empty($routing)) {
            return '';
        }

        $lines = [];
        // Group by scheme
        $grouped = [];
        foreach ($routing as $keyword => $scheme) {
            $grouped[$scheme][] = $keyword;
        }

        foreach ($grouped as $scheme => $keywords) {
            $schemaData = $this->registry->get($scheme);
            $name = $schemaData['name'] ?? $scheme;
            $lines[] = "- If query mentions: " . implode(', ', $keywords) . " → use dataset \"{$scheme}\" ({$name})";
        }

        return implode("\n", $lines);
    }

    /**
     * Build global example queries from config.
     */
    protected function buildGlobalExamples(): string
    {
        $examples = config('naturalquery.global_examples', []);
        if (empty($examples)) {
            return '';
        }

        $lines = [];
        foreach ($examples as $ex) {
            $lines[] = "Q: " . ($ex['natural'] ?? '');
            if (!empty($ex['sql'])) {
                $lines[] = "SQL: " . $ex['sql'];
            }
            if (!empty($ex['note'])) {
                $lines[] = "Note: " . $ex['note'];
            }
            $lines[] = "";
        }

        return implode("\n", $lines);
    }
}
