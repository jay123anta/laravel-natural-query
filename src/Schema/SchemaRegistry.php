<?php

namespace Jayanta\NaturalQuery\Schema;

use Illuminate\Support\Facades\Log;

/**
 * Schema Registry
 *
 * Loads and manages schema configuration files from the config directory.
 * Each .php file in the schemas directory defines one queryable dataset.
 *
 * Provides methods to:
 * - List all available schemes
 * - Get schema details for a specific scheme
 * - Resolve scheme by alias
 * - Get available metrics for a scheme
 * - Build scheme info for LLM prompts
 */
class SchemaRegistry
{
    protected string $configPath;
    protected ?array $schemas = null;

    /** Lazily built lookup of validator-permitted tables, keyed lowercase. */
    protected ?array $allowedTableLookup = null;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    /**
     * Load all schema files from the config directory.
     */
    public function all(): array
    {
        if ($this->schemas !== null) {
            return $this->schemas;
        }

        $this->schemas = [];

        if (!is_dir($this->configPath)) {
            Log::warning('[NaturalQuery:SchemaRegistry] Schema directory not found', ['path' => $this->configPath]);
            return $this->schemas;
        }

        $files = glob($this->configPath . '/*.php');

        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            try {
                $schema = require $file;
                if (is_array($schema)) {
                    $this->schemas[$key] = $schema;
                }
            } catch (\Throwable $e) {
                Log::error('[NaturalQuery:SchemaRegistry] Failed to load schema file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->schemas;
    }

    /**
     * Get a specific schema by key.
     */
    public function get(string $key): ?array
    {
        $schemas = $this->all();
        return $schemas[$key] ?? null;
    }

    /**
     * Check if a schema exists.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get all schema keys.
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Find a scheme key by alias.
     */
    public function findByAlias(string $input): ?string
    {
        $input = strtolower(trim($input));

        foreach ($this->all() as $key => $schema) {
            if (strtolower($key) === $input) {
                return $key;
            }

            foreach ($schema['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $input) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Get the primary table name for a schema.
     */
    public function getTableName(string $key): ?string
    {
        $schema = $this->get($key);
        return $schema['tables']['primary']['name'] ?? null;
    }

    /**
     * Are there several datasets, at least one of which points at another?
     *
     * When true, a question can legitimately need columns from more than one
     * table, so narrowing the prompt to a single dataset can make it
     * unanswerable. Discovery records real foreign keys, so this is a fact
     * about the database rather than a guess.
     */
    public function hasLinkedSchemas(): bool
    {
        $schemas = $this->all();

        if (count($schemas) < 2) {
            return false;
        }

        foreach ($schemas as $schema) {
            if (!empty($schema['tables']['primary']['relationships'])) {
                return true;
            }

            // A hand-written join counts too.
            if (!empty($schema['tables']['primary']['required_join'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The column results are grouped and labelled by.
     *
     * When a schema file does not declare `group_column`, this used to assume
     * a column literally called `name`. On a table without one — most tables —
     * that produced `SELECT name ... GROUP BY name` and a hard SQL error, not
     * a degraded label. `naturalquery:discover` always writes group_column, but
     * the README documents hand-written schema files too, and those are exactly
     * the ones that omit it.
     *
     * So derive it from the schema's own columns instead of guessing a name:
     * the first column marked groupable, else the first column that is not a
     * measure, else simply the first column. Any of those produce valid SQL on
     * any table.
     */
    /**
     * Resolve a dimension the user asked to break results down by.
     *
     * "revenue by region" must group by region, not by whatever the schema
     * nominates as its default group_column. Only columns the schema marks
     * `groupable` qualify — grouping by a measure produces one row per distinct
     * amount, which is noise, and grouping by an unlisted column is how a typo
     * or a hallucinated name would reach the database.
     *
     * Returns null when the request cannot be honoured, so the caller can say
     * so rather than quietly answering a different question.
     */
    public function resolveGroupColumn(string $key, ?string $userDimension): ?string
    {
        if (!$userDimension) {
            return null;
        }

        $wanted = strtolower(trim($userDimension));
        $columns = $this->get($key)['tables']['primary']['columns'] ?? [];

        foreach ($columns as $name => $column) {
            if (empty($column['groupable'])) {
                continue;
            }

            if (strtolower($name) === $wanted) {
                return $name;
            }

            foreach ($column['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $wanted) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Columns the schema allows grouping by, for error messages and prompts.
     *
     * @return array<int, string>
     */
    public function getGroupableColumns(string $key): array
    {
        $columns = $this->get($key)['tables']['primary']['columns'] ?? [];

        return array_keys(array_filter($columns, fn ($c) => !empty($c['groupable'])));
    }

    public function getGroupColumn(string $key): string
    {
        $primary = $this->get($key)['tables']['primary'] ?? [];

        if (!empty($primary['group_column'])) {
            return $primary['group_column'];
        }

        $columns = $primary['columns'] ?? [];

        foreach ($columns as $name => $column) {
            if (!empty($column['groupable'])) {
                return $name;
            }
        }

        // A measure is a thing to total, not a thing to group by.
        foreach ($columns as $name => $column) {
            if (empty($column['aggregatable'])) {
                return $name;
            }
        }

        // Nothing left to reason about: a schema with no columns is broken and
        // naturalquery:doctor reports it. 'name' keeps the old behaviour there.
        return (string) (array_key_first($columns) ?? 'name');
    }

    /**
     * Get all column definitions for a schema's primary table.
     */
    public function getColumns(string $key): array
    {
        $schema = $this->get($key);
        return $schema['tables']['primary']['columns'] ?? [];
    }

    /**
     * Get all metrics (regular columns that are aggregatable/sortable).
     */
    public function getMetrics(string $key): array
    {
        $columns = $this->getColumns($key);
        $metrics = [];

        foreach ($columns as $colName => $colDef) {
            if (($colDef['aggregatable'] ?? false) || ($colDef['sortable'] ?? false)) {
                $metrics[$colName] = $colDef;
            }
        }

        return $metrics;
    }

    /**
     * Get computed metrics for a schema.
     */
    public function getComputedMetrics(string $key): array
    {
        $schema = $this->get($key);
        return $schema['computed_metrics'] ?? [];
    }

    /**
     * Get all allowed table/view names across all schemas.
     */
    public function getAllowedTables(): array
    {
        $tables = [];
        foreach ($this->all() as $schema) {
            if (isset($schema['tables']['primary']['name'])) {
                $tables[] = $schema['tables']['primary']['name'];
            }
            // Include related tables
            foreach ($schema['tables'] ?? [] as $tableKey => $table) {
                if ($tableKey !== 'primary' && isset($table['name'])) {
                    $tables[] = $table['name'];
                }
            }
        }
        return array_unique($tables);
    }

    /**
     * Will the SQL validator accept a query touching this table?
     *
     * Matched on the unqualified name as well as the exact string: Postgres
     * reports foreign key targets schema-qualified (`public.customers`) while a
     * hand-written schema file may simply say `customers`, and the two mean the
     * same table.
     */
    public function allowsTable(string $table): bool
    {
        if ($this->allowedTableLookup === null) {
            $this->allowedTableLookup = [];

            foreach ($this->getAllowedTables() as $allowed) {
                $this->allowedTableLookup[strtolower($allowed)] = true;
                $this->allowedTableLookup[strtolower($this->unqualify($allowed))] = true;
            }
        }

        return isset($this->allowedTableLookup[strtolower($table)])
            || isset($this->allowedTableLookup[strtolower($this->unqualify($table))]);
    }

    /**
     * Tables that a schema file points at through a foreign key but that have
     * no schema file of their own, as [schema key => [table, ...]].
     *
     * These are joins the package deliberately will not offer, because the
     * validator would reject the resulting SQL. Surfaced by `naturalquery:doctor`
     * so a partial discovery run is visible rather than silently limiting.
     */
    public function undescribedRelationshipTargets(): array
    {
        $missing = [];

        foreach ($this->all() as $key => $schema) {
            foreach ($schema['tables'] ?? [] as $table) {
                foreach ($table['relationships'] ?? [] as $rel) {
                    $target = $rel['references_table'] ?? null;

                    if ($target && !$this->allowsTable($target)) {
                        $missing[$key][$target] = true;
                    }
                }
            }
        }

        return array_map(fn ($t) => array_keys($t), $missing);
    }

    protected function unqualify(string $table): string
    {
        $parts = explode('.', $table);

        return end($parts);
    }

    /**
     * Get scheme list formatted for LLM providers.
     *
     * Returns an array of schemes with key, name, aliases, and metrics
     * suitable for passing to LlmProviderInterface::parseIntent().
     */
    public function getSchemeListForLlm(): array
    {
        $list = [];

        foreach ($this->all() as $key => $schema) {
            $metrics = $this->getMetrics($key);
            $computedMetrics = $this->getComputedMetrics($key);

            $allMetrics = array_merge($metrics, $computedMetrics);

            $list[] = [
                'key' => $key,
                'name' => $schema['name'] ?? $key,
                'aliases' => $schema['aliases'] ?? [],
                'metrics' => $allMetrics,
                // Without these, intent mode cannot answer "revenue by region":
                // the model has no way to know region is a column it may group
                // by, so the breakdown is dropped and the default grouping is
                // returned as though it had been asked for.
                'dimensions' => $this->getGroupableColumns($key),
                'default_dimension' => $this->getGroupColumn($key),
                'description' => $schema['description'] ?? '',
            ];
        }

        return $list;
    }

    /**
     * Get available schemes for clarification UI.
     */
    public function getAvailableSchemes(): array
    {
        $result = [];
        foreach ($this->all() as $key => $schema) {
            $result[] = [
                'key' => $key,
                'name' => $schema['name'] ?? $key,
                'description' => $schema['description'] ?? '',
                'aliases' => $schema['aliases'] ?? [],
            ];
        }
        return $result;
    }

    /**
     * Get available metrics for a specific scheme (for clarification UI).
     */
    public function getSchemeMetrics(string $key): array
    {
        $metrics = $this->getMetrics($key);
        $computedMetrics = $this->getComputedMetrics($key);

        $result = [];

        foreach ($metrics as $metricKey => $data) {
            $result[] = [
                'key' => $metricKey,
                'description' => $data['description'] ?? $metricKey,
                'type' => $data['type'] ?? 'neutral',
                'computed' => false,
            ];
        }

        foreach ($computedMetrics as $metricKey => $data) {
            $result[] = [
                'key' => $metricKey,
                'description' => $data['description'] ?? $metricKey,
                'type' => $data['type'] ?? 'neutral',
                'computed' => true,
            ];
        }

        return $result;
    }

    /**
     * Get the database connection for a schema.
     */
    public function getConnection(string $key): ?string
    {
        $schema = $this->get($key);
        return $schema['connection'] ?? config('naturalquery.sql.database_connection');
    }

    /**
     * Get the default metric for a schema.
     */
    public function getDefaultMetric(string $key): ?string
    {
        $schema = $this->get($key);

        if (!empty($schema['default_metric'])) {
            return $schema['default_metric'];
        }

        // Fall back to first metric
        $metrics = $this->getMetrics($key);
        return !empty($metrics) ? array_key_first($metrics) : null;
    }

    /**
     * Get the max limit for a schema (or global default).
     */
    public function getMaxLimit(string $key): ?int
    {
        $schema = $this->get($key);
        return $schema['max_limit'] ?? config('naturalquery.sql.max_limit');
    }

    /**
     * Get example queries for a schema (used in prompt building).
     */
    public function getExampleQueries(string $key): array
    {
        $schema = $this->get($key);
        return $schema['example_queries'] ?? [];
    }

    /**
     * Get LLM instructions for a schema.
     */
    public function getLlmInstructions(string $key): string
    {
        $schema = $this->get($key);
        return trim($schema['llm_instructions'] ?? '');
    }

    /**
     * Resolve a metric name from user input or alias.
     */
    public function resolveMetric(string $key, ?string $userMetric): ?string
    {
        if (!$userMetric) {
            return null;
        }

        $userMetric = strtolower(trim($userMetric));

        // Check regular metrics
        foreach ($this->getMetrics($key) as $metricKey => $metricData) {
            if (strtolower($metricKey) === $userMetric) {
                return $metricKey;
            }
            foreach ($metricData['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $userMetric) {
                    return $metricKey;
                }
            }
        }

        // Check computed metrics
        foreach ($this->getComputedMetrics($key) as $metricKey => $metricData) {
            if (strtolower($metricKey) === $userMetric) {
                return $metricKey;
            }
            foreach ($metricData['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $userMetric) {
                    return $metricKey;
                }
            }
        }

        return null;
    }

    /**
     * Flush the cached schemas (useful after config changes).
     */
    public function flush(): void
    {
        $this->schemas = null;
        $this->allowedTableLookup = null;
    }
}
