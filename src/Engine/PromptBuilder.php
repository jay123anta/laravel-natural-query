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
     * Build a complete SQL generation prompt for a specific dataset.
     *
     * Used in sql_generation mode — AI generates the full SQL query.
     */
    public function buildSqlPrompt(string $datasetKey, string $userQuery): string
    {
        $schema = $this->registry->get($datasetKey);
        if (!$schema) {
            return "Error: Unknown dataset '{$datasetKey}'";
        }

        $dialect = $this->introspector->getDialect();
        $schemaInfo = $this->buildFullSchemaInfo($datasetKey, $schema);
        $examples = $this->buildExamples($datasetKey);
        $llmInstructions = $this->registry->getLlmInstructions($datasetKey);
        $maxLimit = $this->registry->getMaxLimit($datasetKey) ?? config('naturalquery.sql.max_limit');
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
        $corrections = $this->buildCorrections($datasetKey);
        if ($corrections) {
            $prompt .= "\n\nPAST CORRECTIONS (avoid these mistakes):\n{$corrections}\n";
        }

        $prompt .= <<<PROMPT

USER QUERY: "{$userQuery}"

Respond with ONLY a JSON object (no markdown):
{
    "sql": "SELECT ... FROM ... ORDER BY ... LIMIT ...",
    "dataset": "{$datasetKey}",
    "query_type": "ranking|group_detail|aggregation|overview",
    "metric": "main metric column name or null",
    "group_value": "a specific record name to filter to, if the user named one, else null — never a category or column name",
    "order": "DESC or ASC",
    "limit": {$defaultLimit},
    "period": "the date range your WHERE clause applies, as YYYY-MM-DD to YYYY-MM-DD, or null if you filtered no dates",
    "explanation": "Brief explanation of what the query does"
}

If the query cannot be processed, respond with:
{
    "error": "Reason why",
    "needs_clarification": true,
    "clarification_type": "dataset|metric|ambiguous",
    "suggestions": ["suggestion 1", "suggestion 2"]
}

RESPOND WITH JSON ONLY:
PROMPT;

        return $prompt;
    }

    /**
     * Build a multi-dataset SQL generation prompt (dataset not yet identified).
     *
     * AI must first identify which dataset to query, then generate SQL.
     *
     * @param PromptScope|null $scope NQ-001-v2 bounding. Null means today's
     *        behaviour, byte for byte (C1) — every dataset, unfiltered. When
     *        given, all four scope-sensitive sections below render ONLY
     *        `$scope->keys()`, in full (R1: no degraded middle tier), and
     *        `$scope->omitted()` datasets are not named anywhere at all (R2)
     *        — not even to say they were left out.
     */
    public function buildMultiDatasetPrompt(string $userQuery, ?PromptScope $scope = null): string
    {
        $dialect = $this->introspector->getDialect();
        $allSchemaInfo = $this->buildAllSchemasFullInfo($scope);
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
        $routingHints = $this->buildRoutingHints($scope);
        if ($routingHints) {
            $prompt .= "QUERY ROUTING (use these to pick the correct dataset):\n{$routingHints}\n\n";
        }

        // Spell out that these are tables in ONE database. Listing them as
        // "datasets" made models treat them as mutually exclusive and answer a
        // cross-table question with "which dataset did you mean?" instead of
        // joining — which is most questions on a normalised schema.
        $prompt .= "AVAILABLE TABLES — all in the SAME database, and you may JOIN across them\n"
            . "(these are the ONLY tables you can query):\n\n{$allSchemaInfo}\n";

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
8b. When a question needs columns from more than one table, JOIN them using the
    RELATED lines above. Do NOT ask which table to use, and do NOT answer with a
    raw id column when the name it points to is available through a join:
    "top customers by revenue" means joining orders to customers and selecting
    the customer's name, not grouping by customer_id.
8c. Check EVERY part of the question against the table you are selecting from.
    If the measure, the grouping, or a FILTER names something that table does
    not have, the query is not finished — join the table that has it. A filter
    is the easiest one to miss, because the question often does not name the
    table it lives on: "shipments to Europe" filters on a country that may sit
    on the customer, not the shipment. Answering from one table and dropping
    the rest of the question returns a plausible number for a question nobody
    asked.
8d. SELECT the measure alongside the label. A ranking that returns only names,
    with no column for what they are ranked by, does not answer the question.
9. For specific record lookup, match with LOWER(column) = LOWER('value'), falling
   back to LOWER(column) LIKE LOWER('%value%'). Never use ILIKE — it is
   PostgreSQL-only and a syntax error on MySQL.
PROMPT;

        // Global example queries (cross-schema)
        $globalExamples = $this->buildGlobalExamples();
        if ($globalExamples) {
            $prompt .= "\n\nGLOBAL EXAMPLES:\n{$globalExamples}\n";
        }

        // Include past corrections for all datasets
        $corrections = $this->buildAllCorrections($scope);
        if ($corrections) {
            $prompt .= "\n\nPAST CORRECTIONS (avoid these mistakes):\n{$corrections}\n";
        }

        $prompt .= <<<PROMPT

USER QUERY: "{$userQuery}"

Return JSON only (no markdown):
{
    "sql": "SELECT ... FROM ... ORDER BY ... LIMIT ...",
    "dataset": "dataset_key",
    "query_type": "ranking|group_detail|aggregation|overview",
    "metric": "main metric name or null",
    "group_value": "specific group name if mentioned, or null",
    "order": "DESC or ASC",
    "limit": {$defaultLimit},
    "period": "the date range your WHERE clause applies, as YYYY-MM-DD to YYYY-MM-DD, or null if you filtered no dates",
    "explanation": "Brief explanation"
}

Or if unclear:
{
    "error": "Reason",
    "needs_clarification": true,
    "clarification_type": "dataset|metric|ambiguous",
    "suggestions": ["try asking about X", "try asking about Y"]
}

JSON ONLY:
PROMPT;

        return $prompt;
    }

    /**
     * Build FULL schema info for a single dataset — includes everything the AI needs.
     *
     * @param PromptScope|null $scope threaded through only to keep joinsFor()
     *        from naming an out-of-scope neighbour's table — every OTHER
     *        field here (required_filter, required_join, select_override,
     *        computed metrics) is unconditional: a dataset that reaches this
     *        method is IN scope by definition (its caller decided that), and
     *        R1 forbids rendering it in anything less than full.
     */
    protected function buildFullSchemaInfo(string $datasetKey, array $schema, ?PromptScope $scope = null): string
    {
        $lines = [];
        $primary = $schema['tables']['primary'] ?? [];
        $tableName = $primary['name'] ?? 'unknown';
        $groupColumn = $primary['group_column'] ?? 'name';
        $joinClause = $primary['required_join'] ?? null;
        $selectOverride = $primary['select_override'] ?? null;

        $lines[] = "TABLE: {$tableName}";
        $lines[] = "  Dataset: {$datasetKey} ({$schema['name']})";
        $lines[] = "  Description: " . ($schema['description'] ?? '');
        $lines[] = "  Group/Filter Column: {$groupColumn}";

        if ($joinClause) {
            $lines[] = "  REQUIRED JOIN: {$joinClause}";
            $lines[] = "  Use \"{$selectOverride}\" in SELECT for the group column (alias it AS {$groupColumn})";
        }

        // Foreign keys, so a question whose answer spans tables can be joined
        // rather than answered with raw id values. Without this the model sees
        // `customer_id` as just another integer and groups by it.
        foreach ($this->joinsFor($tableName, $primary['relationships'] ?? [], $scope) as $line) {
            $lines[] = $line;
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
     * Build full schema info for ALL datasets — used in multi-dataset prompts.
     */
    /**
     * Render foreign keys as ready-to-use JOIN clauses.
     *
     * Grouped by constraint, because a composite key is ONE join with an AND,
     * not several. Emitting a line per column invited two joins to the same
     * table, or a join on half the key — and half a composite key matches rows
     * it should not, producing a total that is silently too large.
     *
     * Hand-written relationships without a constraint name fall back to
     * grouping by referenced table, which is right in every case except two
     * separate single-column keys pointing at the same table.
     *
     * @param array<int, array<string, mixed>> $relationships
     * @param PromptScope|null $scope when given, a relationship whose target
     *        is not one of `$scope->scopedTables()` is skipped too — a
     *        dataset pulled into scope by ONE relationship can still declare
     *        others the FK expansion did not reach (expansion is a single
     *        hop from the SEEDS, not from every scope member transitively),
     *        and rendering that further neighbour's physical table name here
     *        would name a dataset R2 says must not be mentioned at all.
     * @return string[]
     */
    protected function joinsFor(string $tableName, array $relationships, ?PromptScope $scope = null): array
    {
        $byConstraint = [];

        foreach ($relationships as $rel) {
            if (empty($rel['column']) || empty($rel['references_table'])) {
                continue;
            }

            // Never advertise a join the validator will refuse. Discovering a
            // subset of tables (`discover --table=orders`) leaves foreign keys
            // pointing at tables that have no schema file, and the allowed-table
            // list is built from schema files. Suggesting those joins turned a
            // partial install into "query validation failed" on SQL this package
            // had itself asked the model to write. The whitelist is where the
            // user drew the line; the prompt follows it.
            if (!$this->registry->allowsTable($rel['references_table'])) {
                continue;
            }

            if (!$this->tableInScope($rel['references_table'], $scope)) {
                continue;
            }

            $key = $rel['constraint'] ?? ('table:' . $rel['references_table']);
            $byConstraint[$key]['table'] = $rel['references_table'];
            $byConstraint[$key]['on'][] = sprintf(
                '%s.%s = %s.%s',
                $rel['references_table'],
                $rel['references_column'] ?? 'id',
                $tableName,
                $rel['column']
            );
        }

        $lines = [];

        foreach ($byConstraint as $join) {
            $conditions = implode(' AND ', $join['on']);
            $note = count($join['on']) > 1
                ? ' (composite key — ALL conditions are required)'
                : '';

            $lines[] = sprintf(
                '  RELATED: JOIN %s ON %s%s — do this to use its columns',
                $join['table'],
                $conditions,
                $note
            );
        }

        return $lines;
    }

    protected function buildAllSchemasFullInfo(?PromptScope $scope = null): string
    {
        $sections = [];

        foreach ($this->registry->all() as $key => $schema) {
            if ($scope !== null && !$scope->isDataset($key)) {
                continue; // R2: an omitted dataset is absent, not degraded
            }

            $sections[] = $this->buildFullSchemaInfo($key, $schema, $scope);
            $sections[] = ""; // blank line between schemas
        }

        return implode("\n", $sections);
    }

    /**
     * Is $table one of the physical tables PromptScope allows into THIS
     * prompt? Compared unqualified, same rule as SchemaRegistry::allowsTable()
     * — Postgres reports a foreign key target schema-qualified while a
     * hand-written schema file may not. Null $scope means no scoping is in
     * effect (C1), so every whitelisted table is in scope.
     */
    protected function tableInScope(string $table, ?PromptScope $scope): bool
    {
        if ($scope === null) {
            return true;
        }

        $needle = strtolower($this->unqualifyTableName($table));

        foreach ($scope->scopedTables() as $scoped) {
            if (strtolower($this->unqualifyTableName($scoped)) === $needle) {
                return true;
            }
        }

        return false;
    }

    protected function unqualifyTableName(string $table): string
    {
        $parts = explode('.', $table);

        return end($parts);
    }

    /**
     * Build example queries section.
     */
    protected function buildExamples(string $datasetKey): string
    {
        $examples = $this->registry->getExampleQueries($datasetKey);
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
     * Build corrections section for a specific dataset.
     */
    protected function buildCorrections(string $datasetKey): string
    {
        $corrections = $this->feedback->getCorrectionsForPrompt($datasetKey);
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
     * Build corrections section for all datasets.
     */
    protected function buildAllCorrections(?PromptScope $scope = null): string
    {
        $corrections = $this->feedback->getAllCorrectionsForPrompt();
        if (empty($corrections)) {
            return '';
        }

        $lines = [];
        foreach ($corrections as $c) {
            // A correction for an omitted dataset is schema knowledge about a
            // table that is not in this prompt at all — R2.
            if ($scope !== null && !$scope->isDataset($c['dataset'] ?? '')) {
                continue;
            }

            $lines[] = "- [{$c['dataset']}] When user asked: \"{$c['query']}\"";
            $lines[] = "  Problem: {$c['correction']}";
            if (!empty($c['corrected_sql'])) {
                $lines[] = "  Correct SQL: {$c['corrected_sql']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build routing hints from config for multi-dataset prompts.
     */
    protected function buildRoutingHints(?PromptScope $scope = null): string
    {
        $routing = config('naturalquery.query_routing', []);
        if (empty($routing)) {
            return '';
        }

        $lines = [];
        // Group by dataset
        $grouped = [];
        foreach ($routing as $keyword => $dataset) {
            $grouped[$dataset][] = $keyword;
        }

        foreach ($grouped as $dataset => $keywords) {
            // A rule pointing at an omitted dataset would name a table the
            // model has no other information about in this prompt — R2.
            if ($scope !== null && !$scope->isDataset($dataset)) {
                continue;
            }

            $schemaData = $this->registry->get($dataset);
            $name = $schemaData['name'] ?? $dataset;
            $lines[] = "- If query mentions: " . implode(', ', $keywords) . " → use dataset \"{$dataset}\" ({$name})";
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
