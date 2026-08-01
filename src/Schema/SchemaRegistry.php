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
     * Get the group column for a schema.
     */
    public function getGroupColumn(string $key): string
    {
        $schema = $this->get($key);
        return $schema['tables']['primary']['group_column'] ?? 'name';
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
    }
}
