<?php

namespace Jayanta\NaturalQuery\Security;

use Jayanta\NaturalQuery\Contracts\SqlValidatorInterface;
use Illuminate\Support\Facades\Log;

/**
 * SQL Validator - Defense-in-Depth Security Layer
 *
 * Validates AI-generated SQL before execution to ensure:
 * 1. Only SELECT queries are allowed
 * 2. Only whitelisted tables/views can be queried
 * 3. No SQL injection patterns
 * 4. No dangerous keywords (INSERT, DROP, etc.)
 * 5. LIMIT enforcement
 *
 * This is the last line of defense - even if the AI generates
 * dangerous SQL, it will be caught here before execution.
 */
class SqlValidator implements SqlValidatorInterface
{
    /**
     * Validate a SQL query against security rules.
     *
     * @param string $sql The SQL to validate
     * @param array $allowedTables List of allowed table/view names
     * @param array $options {
     *   max_limit?: int,
     *   forbidden_keywords?: string[],
     *   allow_union_all?: bool,
     *   allow_cte?: bool,
     *   require_limit?: bool
     * }
     * @return array {valid: bool, reason: string|null}
     */
    public function validate(string $sql, array $allowedTables, array $options = []): array
    {
        $sql = trim($sql);

        // Remove trailing semicolons for validation
        $sqlClean = rtrim($sql, '; ');

        // 1. Must start with SELECT (or WITH for CTEs if allowed)
        $allowCte = $options['allow_cte'] ?? config('naturalquery.sql.allow_cte', true);

        if ($allowCte) {
            if (!preg_match('/^\s*(SELECT|WITH)\s/i', $sqlClean)) {
                return $this->fail('Query must start with SELECT or WITH');
            }
        } else {
            if (!preg_match('/^\s*SELECT\s/i', $sqlClean)) {
                return $this->fail('Query must start with SELECT');
            }
        }

        // 2. Forbidden keywords check
        $forbiddenKeywords = $options['forbidden_keywords']
            ?? config('naturalquery.sql.forbidden_keywords', [
                'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE',
                'EXEC', 'EXECUTE', 'GRANT', 'REVOKE', 'INTO',
                'COPY', 'pg_', 'information_schema', 'pg_catalog',
                'DO',  // PL/pgSQL anonymous blocks
            ]);

        // Block PL/pgSQL dollar-quoting (DO $$ ... $$)
        if (str_contains($sqlClean, '$$')) {
            return $this->fail('Dollar-quoting ($$) not allowed');
        }

        // If UNION ALL is not allowed, add UNION to forbidden list
        $allowUnionAll = $options['allow_union_all'] ?? config('naturalquery.sql.allow_union_all', true);
        if (!$allowUnionAll) {
            $forbiddenKeywords[] = 'UNION';
        }

        foreach ($forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $sqlClean)) {
                Log::warning('[NaturalQuery:SqlValidator] Forbidden keyword detected', [
                    'keyword' => $keyword,
                    'sql' => substr($sqlClean, 0, 200),
                ]);
                return $this->fail("Forbidden keyword: {$keyword}");
            }
        }

        // 3. SQL injection pattern detection
        $injectionPatterns = [
            '/;\s*--/' => 'Statement terminator with comment',
            '/;\s*\/\*/' => 'Statement terminator with block comment',
            '/\bOR\s+1\s*=\s*1/i' => 'OR 1=1 tautology',
            '/\bAND\s+1\s*=\s*1/i' => 'AND 1=1 tautology',
            '/\'\s*OR\s*\'/i' => 'String-based OR injection',
            '/\'\s*;\s*/' => 'String termination with semicolon',
            '/--\s*$/' => 'Trailing SQL comment',
            '/;\s*SELECT\b/i' => 'Stacked query attempt',
            '/\bSLEEP\s*\(/i' => 'Time-based injection',
            '/\bBENCHMARK\s*\(/i' => 'Time-based injection',
            '/\bWAITFOR\s+DELAY/i' => 'Time-based injection',
            '/\bLOAD_FILE\s*\(/i' => 'File read attempt',
            '/\bINTO\s+(OUT|DUMP)FILE/i' => 'File write attempt',
        ];

        foreach ($injectionPatterns as $pattern => $description) {
            if (preg_match($pattern, $sqlClean)) {
                Log::warning('[NaturalQuery:SqlValidator] Injection pattern detected', [
                    'pattern' => $description,
                    'sql' => substr($sqlClean, 0, 200),
                ]);
                return $this->fail("Potential SQL injection: {$description}");
            }
        }

        // 4. Verify ONLY allowed tables/views are referenced
        // Extract all table references from FROM and JOIN clauses
        if (!empty($allowedTables)) {
            $referencedTables = $this->extractTableReferences($sqlClean);

            if (empty($referencedTables)) {
                return $this->fail('Could not identify any table references in query');
            }

            // Check that EVERY referenced table is in the whitelist.
            //
            // Matching rules:
            //   - exact match ('public.orders' vs 'public.orders', 'orders' vs 'orders')
            //   - a BARE reference may match a schema-qualified whitelist entry
            //     ('orders' matches allowed 'public.orders' — the DB resolves it
            //     via search_path to the same table)
            //   - a SCHEMA-QUALIFIED reference must match exactly. It must NOT
            //     match a bare whitelist entry: if 'users' is whitelisted,
            //     'other_schema.users' would otherwise slip through and expose a
            //     same-named table in a different schema (cross-schema bypass).
            $allowedLower = array_map('strtolower', $allowedTables);
            foreach ($referencedTables as $table) {
                $tableLower = strtolower($table);
                $found = false;
                foreach ($allowedLower as $allowed) {
                    if ($tableLower === $allowed
                        || (!str_contains($tableLower, '.') && str_ends_with($allowed, '.' . $tableLower))) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    Log::warning('[NaturalQuery:SqlValidator] Unauthorized table reference', [
                        'table' => $table,
                        'sql' => substr($sqlClean, 0, 200),
                    ]);
                    return $this->fail("Unauthorized table: {$table}");
                }
            }
        }

        // 5. LIMIT enforcement
        $requireLimit = $options['require_limit'] ?? true;
        $maxLimit = $options['max_limit'] ?? config('naturalquery.sql.max_limit');

        if ($requireLimit && !preg_match('/\bLIMIT\s+\d+/i', $sqlClean)) {
            return $this->fail('Query must include a LIMIT clause');
        }

        if ($maxLimit !== null && preg_match('/\bLIMIT\s+(\d+)/i', $sqlClean, $matches)) {
            if (intval($matches[1]) > $maxLimit) {
                return $this->fail("LIMIT cannot exceed {$maxLimit}");
            }
        }

        // 6. Check for multiple statements (semicolons not at end)
        $withoutStrings = preg_replace("/'[^']*'/", '', $sqlClean);
        if (substr_count($withoutStrings, ';') > 0) {
            return $this->fail('Multiple SQL statements not allowed');
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Extract table/view names from FROM and JOIN clauses.
     *
     * Handles: FROM schema.table, FROM table alias, JOIN schema.table ON ...,
     * and tables inside CTE definitions (WITH x AS (SELECT ... FROM table))
     */
    protected function extractTableReferences(string $sql): array
    {
        $tables = [];

        // Match FROM clause: FROM schema.table, FROM table1, table2
        // Handles comma-separated tables and tables inside CTEs
        if (preg_match_all('/\bFROM\s+([a-zA-Z_][a-zA-Z0-9_.,\s]*?)(?:\s+WHERE\b|\s+ORDER\b|\s+GROUP\b|\s+LIMIT\b|\s+HAVING\b|\s+ON\b|\s*\)|\s*$)/i', $sql, $matches)) {
            foreach ($matches[1] as $fromClause) {
                // Split comma-separated tables: "public.orders, secret_data alias"
                $parts = preg_split('/\s*,\s*/', trim($fromClause));
                foreach ($parts as $part) {
                    // Extract table name (first identifier, ignore alias)
                    if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_.]*)/', trim($part), $m)) {
                        $tables[] = $m[1];
                    }
                }
            }
        }

        // Match JOIN clause: [LEFT|RIGHT|INNER|CROSS] JOIN schema.table [alias]
        if (preg_match_all('/\bJOIN\s+([a-zA-Z_][a-zA-Z0-9_.]*)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Remove CTE names (WITH x AS ...) — these are not real tables
        if (preg_match_all('/\bWITH\s+([a-zA-Z_]\w*)\s+AS\s*\(/i', $sql, $cteMatches)) {
            $tables = array_diff($tables, $cteMatches[1]);
        }

        // Also remove common SQL aliases that look like table names
        // (subquery aliases after closing parenthesis)
        if (preg_match_all('/\)\s+(?:AS\s+)?([a-zA-Z_]\w*)/i', $sql, $aliasMatches)) {
            $tables = array_diff($tables, $aliasMatches[1]);
        }

        return array_unique(array_values(array_filter($tables)));
    }

    /**
     * Return a validation failure result.
     */
    protected function fail(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason];
    }
}
