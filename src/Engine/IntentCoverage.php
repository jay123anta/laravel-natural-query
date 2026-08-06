<?php

namespace Jayanta\NaturalQuery\Engine;

/**
 * Does the intent contract have somewhere to put this question?
 *
 * Intent mode expresses a deliberately small slice of SQL: one measure, one
 * grouping, one name filter, one period, an order and a limit. That covers most
 * questions and is safer than free SQL generation — deterministic, no
 * hallucinated columns, no clause the validator has to catch.
 *
 * The danger is what happens at the edge. A question needing something the
 * contract cannot represent did not fail: the unrepresentable part was dropped
 * and the remainder answered, with no sign that a narrower question had been
 * substituted. Four defects of exactly that shape were found in three days —
 * a dropped GROUP BY, a dropped metric, a dropped clarification, a dropped
 * period — each fixed by widening the contract by one field.
 *
 * Widening it one field at a time never closes the gap; there is always
 * another clause. So this asks the opposite question: does the wording show
 * the question needs more than the contract holds? If so, route it to SQL
 * generation, which can express arbitrary (still validated, still SELECT-only)
 * SQL.
 *
 * Escalating is close to free — intent parsing and SQL generation are one API
 * call each — so a false positive costs nothing but determinism, while a false
 * negative is a confidently wrong number. The patterns lean towards escalating.
 */
class IntentCoverage
{
    /**
     * Wording that needs SQL the intent contract cannot express, by the SQL
     * component it implies. Named so the metadata can say WHY it escalated.
     */
    protected const BEYOND_CONTRACT = [
        // HAVING — filtering groups by an aggregate
        'having' => [
            '/\b(?:with|having|that\s+have|who\s+have)\s+(?:more|less|fewer|greater|at\s+least|at\s+most|over|under)\b/i',
            '/\bmore\s+than\s+\d+\s+\w+/i',
        ],
        // WHERE on a measure — the contract filters by name and period only
        'numeric_filter' => [
            '/\b(?:over|above|under|below|greater\s+than|less\s+than|at\s+least|at\s+most)\s+[\d£$€₹]/i',
            '/\bbetween\s+[\d£$€₹][\d,.]*\s+and\s+[\d£$€₹]/i',
        ],
        // Negation — no NOT anywhere in the contract
        'exclusion' => [
            '/\b(?:excluding|except|other\s+than|apart\s+from|but\s+not|without\s+(?:the\s+)?(?:any\s+)?\w+)\b/i',
            '/\bnot\s+(?:including|counting|in)\b/i',
        ],
        // DISTINCT
        'distinct' => [
            '/\b(?:distinct|unique)\s+\w+/i',
            '/\bhow\s+many\s+different\b/i',
        ],
        // Ratios and shares — arithmetic between two aggregates
        'ratio' => [
            '/\b(?:percentage|percent|share|proportion|ratio)\s+of\b/i',
            '/\bwhat\s+(?:percent|percentage)\b/i',
            '/\bper\s+(?:customer|order|user|head|capita)\b/i',
        ],
        // Per-group superlatives — a window function or correlated subquery
        'per_group_top' => [
            '/\btop\s+\d+\s+\w+\s+(?:in|for|per)\s+each\b/i',
            '/\b(?:for|in)\s+each\s+\w+.*\b(?:top|best|highest|lowest)\b/i',
            '/\bper\s+\w+\s+(?:top|best|highest)\b/i',
        ],
    ];

    /**
     * The SQL component this question needs and the contract lacks, or null
     * when intent mode can express it.
     */
    public function exceeds(string $query): ?string
    {
        if (!config('naturalquery.sql.escalate_beyond_intent', true)) {
            return null;
        }

        foreach (self::BEYOND_CONTRACT as $component => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $query)) {
                    return $component;
                }
            }
        }

        return null;
    }
}
