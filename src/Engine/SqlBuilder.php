<?php

namespace Jayanta\NaturalQuery\Engine;

use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Safe SQL Builder
 *
 * Builds SQL queries from structured intents.
 * NEVER accepts raw SQL from AI - only structured intent data.
 * All table/column names come from schema config, not user input.
 *
 * Extracted from VoiceSqlBuilderService in CMDashboard.
 */
class SqlBuilder
{
    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Build SQL query from parsed intent.
     *
     * @param array $intent Parsed intent {scheme, metric, district, limit, order}
     * @return array {success, sql, scheme, scheme_name, metric, query_type, ...}
     */
    public function buildQuery(array $intent): array
    {
        try {
            $schemeKey = $intent['scheme'] ?? null;
            if (!$schemeKey) {
                return $this->errorResponse('No scheme specified');
            }

            $schema = $this->registry->get($schemeKey);
            if (!$schema) {
                return $this->errorResponse("Unknown scheme: {$schemeKey}");
            }

            $tableName = $this->registry->getTableName($schemeKey);
            if (!$tableName) {
                return $this->errorResponse("No table configured for scheme: {$schemeKey}");
            }

            // "revenue by region" asks for a different breakdown than the
            // schema's default. Without this the dimension was dropped and the
            // answer came back grouped by group_column — correctly formatted,
            // confidently worded, and about a question nobody asked.
            $requestedDimension = $intent['group_by'] ?? null;
            $groupColumn = $requestedDimension
                ? $this->registry->resolveGroupColumn($schemeKey, $requestedDimension)
                : $this->registry->getGroupColumn($schemeKey);

            if ($requestedDimension && !$groupColumn) {
                $groupable = $this->registry->getGroupableColumns($schemeKey);

                return $this->errorResponse(
                    "Cannot group by '{$requestedDimension}'."
                    . ($groupable
                        ? ' Available breakdowns: ' . implode(', ', $groupable) . '.'
                        : ' This dataset has no columns marked groupable.')
                );
            }

            // Resolve metric
            $metric = $this->registry->resolveMetric($schemeKey, $intent['metric'] ?? null);
            if (!$metric) {
                $metric = $this->registry->getDefaultMetric($schemeKey);
            }

            if (!$metric) {
                return $this->errorResponse('No metric could be resolved');
            }

            // Get metric SQL expression (handles computed metrics)
            $metricExpr = $this->getMetricExpression($schemeKey, $metric);
            $metricData = $this->getMetricData($schemeKey, $metric);

            // Validate and sanitize parameters
            // 'district' is the pre-1.0 name for this field. Still accepted so
            // a custom prompt override, a cached intent, or a third-party
            // provider written against the old contract keeps working.
            $groupValue = $this->sanitizeGroupValue(
                $intent['group_value'] ?? $intent['district'] ?? null
            );
            $maxLimit = $this->registry->getMaxLimit($schemeKey) ?? config('naturalquery.sql.max_limit');
            $defaultLimit = $schema['defaults']['limit'] ?? config('naturalquery.sql.default_limit', 100);
            $limit = intval($intent['limit'] ?? $defaultLimit);
            $limit = max(1, $maxLimit ? min($limit, $maxLimit) : $limit);

            $order = strtoupper($intent['order'] ?? $schema['defaults']['order'] ?? 'DESC');
            $order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';

            // Check if this schema requires a JOIN (e.g., for district name lookup)
            $joinClause = $schema['tables']['primary']['required_join'] ?? null;
            $selectOverride = $schema['tables']['primary']['select_override'] ?? null;

            // If JOIN is needed, the group column reference comes from the
            // joined table. select_override maps the schema's DEFAULT group
            // column through that join, so it must not be applied when the user
            // asked to group by something else — doing so would label region
            // totals with district names.
            $fromClause = $tableName . ($joinClause ? ' ' . $joinClause : '');
            $useOverride = $selectOverride && !$requestedDimension;
            $groupColumnSelect = $useOverride
                ? "{$selectOverride} AS {$groupColumn}"
                : $groupColumn;
            $groupColumnRef = $useOverride ? $selectOverride : $groupColumn;

            // Transactional tables (many rows per group value, e.g. one row per
            // order) need GROUP BY + SUM; pre-aggregated tables (one row per
            // group value, e.g. one row per district) must be read as-is.
            // The schema decides: a plain column marked 'aggregatable' is
            // summed per group; a computed metric whose expression already
            // aggregates (SUM/COUNT/AVG/...) is grouped as-is.
            $isComputed = isset($this->registry->getComputedMetrics($schemeKey)[$metric]);
            if ($isComputed) {
                $aggregate = $this->isAggregateExpression($metricExpr);
            } else {
                $aggregate = $this->isAggregatableColumn($schemeKey, $metric);
                if ($aggregate) {
                    $metricExpr = "SUM({$metricExpr})";
                }
            }

            // Determine query type and build SQL
            $bindings = [];
            if ($groupValue) {
                $result = $this->buildGroupValueQuery($fromClause, $groupColumnSelect, $groupColumnRef, $metricExpr, $metric, $groupValue, $schemeKey);
                $sql = $result['sql'];
                $bindings = $result['bindings'];
                $queryType = 'group_detail';
            } else {
                $sql = $this->buildRankingQuery($fromClause, $groupColumnSelect, $groupColumnRef, $metricExpr, $metric, $order, $limit, $aggregate);
                $queryType = 'ranking';
            }

            // Apply required filter if defined
            $requiredFilter = $schema['tables']['primary']['required_filter'] ?? null;
            if ($requiredFilter) {
                $sql = $this->applyRequiredFilter($sql, $requiredFilter);
            }

            return [
                'success' => true,
                'sql' => $sql,
                'bindings' => $bindings,
                'scheme' => $schemeKey,
                'scheme_name' => $schema['name'] ?? $schemeKey,
                'metric' => $metric,
                'metric_description' => $metricData['description'] ?? $metric,
                'metric_unit' => $metricData['unit'] ?? 'units',
                'metric_type' => $metricData['type'] ?? 'neutral',
                'group_value' => $groupValue,
                'limit' => $limit,
                'order' => $order,
                'query_type' => $queryType,
                'group_column' => $groupColumn,
            ];

        } catch (\Exception $e) {
            Log::error('[NaturalQuery:SqlBuilder] Error', ['error' => $e->getMessage()]);
            return $this->errorResponse('SQL build error: ' . $e->getMessage());
        }
    }

    /**
     * Build ranking query (top/bottom N).
     *
     * @param string $fromClause Table name, possibly with JOIN clause
     * @param string $groupColumnSelect The SELECT expression for the group column (may include alias)
     * @param string $groupColumnRef The reference to group column for ORDER BY / WHERE
     * @param string $metricExpr SQL expression for the metric (already wrapped
     *                           in SUM() for aggregatable plain columns)
     * @param string $metricAlias Alias name for the metric
     * @param string $order ASC or DESC
     * @param int $limit Result limit
     * @param bool $aggregate Whether to GROUP BY the group column (transactional tables)
     */
    protected function buildRankingQuery(
        string $fromClause,
        string $groupColumnSelect,
        string $groupColumnRef,
        string $metricExpr,
        string $metricAlias,
        string $order,
        int $limit,
        bool $aggregate = false
    ): string {
        $groupBy = $aggregate ? " GROUP BY {$groupColumnRef}" : '';

        return "SELECT {$groupColumnSelect}, {$metricExpr} AS {$metricAlias} FROM {$fromClause}{$groupBy} ORDER BY {$metricExpr} {$order} LIMIT {$limit}";
    }

    /**
     * Build group-value-specific query (e.g., district detail).
     *
     * Smart matching: exact match first, then partial match fallback.
     */
    /**
     * Build group-value-specific query (e.g., district detail).
     *
     * Smart matching: exact match first, then partial match fallback.
     * Returns [sql, bindings] for parameterized execution.
     */
    protected function buildGroupValueQuery(
        string $fromClause,
        string $groupColumnSelect,
        string $groupColumnRef,
        string $metricExpr,
        string $metricAlias,
        string $groupValue,
        string $schemeKey
    ): array {
        // Transactional tables need per-group aggregation for the detail view
        // too — otherwise "revenue for <customer>" returns one arbitrary row.
        $plainMetrics = $this->registry->getMetrics($schemeKey);
        $computedMetrics = $this->registry->getComputedMetrics($schemeKey);

        $aggregate = false;
        foreach ($plainMetrics as $key => $data) {
            if ($data['aggregatable'] ?? false) {
                $aggregate = true;
                break;
            }
        }

        // Get all metric columns for detail view
        $metricColumns = [];
        foreach ($plainMetrics as $key => $data) {
            if ($aggregate) {
                // SUM aggregatable columns; MAX is a safe representative for
                // the rest (identical value on pre-aggregated tables)
                $fn = ($data['aggregatable'] ?? false) ? 'SUM' : 'MAX';
                $metricColumns[] = "{$fn}({$key}) AS {$key}";
            } else {
                $metricColumns[] = $key;
            }
        }
        foreach ($computedMetrics as $key => $data) {
            // In aggregate mode, only expressions that aggregate themselves
            // are valid under GROUP BY; scalar expressions are skipped.
            if (!$aggregate || $this->isAggregateExpression($data['expression'])) {
                $metricColumns[] = "{$data['expression']} AS {$key}";
            }
        }

        $columnsStr = $groupColumnSelect . ', ' . implode(', ', $metricColumns);
        $groupBy = $aggregate ? " GROUP BY {$groupColumnRef}" : '';

        // Use parameterized queries — NEVER interpolate user values into SQL.
        //
        // LOWER(col) LIKE LOWER(?) rather than ILIKE: ILIKE is PostgreSQL-only
        // and is a hard syntax error on MySQL and MariaDB, which this package
        // also supports — every named-record lookup failed there. The LOWER()
        // form is ANSI and behaves identically on all of them. It does forgo
        // an index, but this query is a single-record lookup with LIMIT 1.
        $sql = "SELECT {$columnsStr} FROM {$fromClause} WHERE LOWER({$groupColumnRef}) = LOWER(?) OR LOWER({$groupColumnRef}) LIKE LOWER(?) ESCAPE '!'{$groupBy} ORDER BY CASE WHEN LOWER({$groupColumnRef}) = LOWER(?) THEN 0 ELSE 1 END LIMIT 1";
        $bindings = [$groupValue, '%' . $this->escapeLikeValue($groupValue) . '%', $groupValue];

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * Build aggregation query (totals).
     */
    public function buildAggregationQuery(array $intent): array
    {
        try {
            $schemeKey = $intent['scheme'] ?? null;
            $schema = $this->registry->get($schemeKey);

            if (!$schema) {
                return $this->errorResponse("Unknown scheme: {$schemeKey}");
            }

            $tableName = $this->registry->getTableName($schemeKey);
            $joinClause = $schema['tables']['primary']['required_join'] ?? null;
            $fromClause = $tableName . ($joinClause ? ' ' . $joinClause : '');

            $metric = $this->registry->resolveMetric($schemeKey, $intent['metric'] ?? null)
                ?? $this->registry->getDefaultMetric($schemeKey);

            $metricExpr = $this->getMetricExpression($schemeKey, $metric);

            // A computed metric that already aggregates (COUNT/SUM/AVG/...)
            // must not be wrapped in SUM() — nested aggregates are invalid SQL.
            $sql = $this->isAggregateExpression($metricExpr)
                ? "SELECT {$metricExpr} AS {$metric} FROM {$fromClause}"
                : "SELECT SUM({$metricExpr}) AS total_{$metric} FROM {$fromClause}";

            return [
                'success' => true,
                'sql' => $sql,
                'scheme' => $schemeKey,
                'scheme_name' => $schema['name'] ?? $schemeKey,
                'metric' => $metric,
                'query_type' => 'aggregation',
            ];
        } catch (\Exception $e) {
            return $this->errorResponse('Aggregation error: ' . $e->getMessage());
        }
    }

    /**
     * Does this SQL expression contain an aggregate function?
     */
    protected function isAggregateExpression(string $expr): bool
    {
        return (bool) preg_match('/\b(SUM|COUNT|AVG|MIN|MAX)\s*\(/i', $expr);
    }

    /**
     * Is this plain column marked aggregatable in the schema?
     */
    protected function isAggregatableColumn(string $schemeKey, string $column): bool
    {
        $columns = $this->registry->getColumns($schemeKey);

        return (bool) ($columns[$column]['aggregatable'] ?? false);
    }

    /**
     * Get SQL expression for a metric (handles computed metrics).
     */
    protected function getMetricExpression(string $schemeKey, string $metric): string
    {
        $computedMetrics = $this->registry->getComputedMetrics($schemeKey);

        if (isset($computedMetrics[$metric])) {
            return $computedMetrics[$metric]['expression'];
        }

        return $metric;
    }

    /**
     * Get metric metadata.
     */
    protected function getMetricData(string $schemeKey, string $metric): array
    {
        $metrics = $this->registry->getMetrics($schemeKey);
        if (isset($metrics[$metric])) {
            return $metrics[$metric];
        }

        $computed = $this->registry->getComputedMetrics($schemeKey);
        return $computed[$metric] ?? [];
    }

    /**
     * Clean the value naming a single record, without destroying it.
     *
     * This used to strip quotes, %, _ and backslashes, then reject anything
     * that was not purely ASCII letters, spaces, hyphens and dots. That threw
     * away most real identifiers — "A-01", "Bin 7", "3M", "H&M",
     * "INV-2024-88", "Zürich" were all rejected, and "ACME_CORP" was silently
     * rewritten to "ACMECORP".
     *
     * Rejecting was the dangerous part. A null value means no filter, so the
     * builder fell through to a ranking query and answered a question about
     * one specific record with the top-ranked row instead — confidently, with
     * no warning. That is the worst thing this package can do.
     *
     * Injection is already prevented by binding the value as a parameter, not
     * by mangling it, so this now only removes control characters and caps the
     * length. LIKE wildcards inside the value are escaped where the LIKE
     * pattern is built, so they match literally rather than being deleted.
     */
    protected function sanitizeGroupValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Control characters (including NUL) have no place in an identifier
        // and can confuse logging and drivers.
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 255);
    }

    /**
     * Escape LIKE wildcards so the value matches literally.
     *
     * Without this, an underscore in something like ACME_CORP is a
     * single-character wildcard. ESCAPE is given explicitly because the
     * default escape character is not consistent across engines.
     */
    protected function escapeLikeValue(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Apply a required filter to a SQL query.
     */
    protected function applyRequiredFilter(string $sql, string $filter): string
    {
        if (stripos($sql, 'WHERE') !== false) {
            return preg_replace('/\bWHERE\b/i', "WHERE ({$filter}) AND", $sql, 1);
        }

        // Insert WHERE before GROUP BY, ORDER BY or LIMIT (whichever comes first)
        if (preg_match('/\b(GROUP BY|ORDER BY|LIMIT)\b/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1];
            return substr($sql, 0, $pos) . "WHERE {$filter} " . substr($sql, $pos);
        }

        return $sql . " WHERE {$filter}";
    }

    protected function errorResponse(string $message): array
    {
        return ['success' => false, 'error' => $message, 'sql' => null];
    }
}
