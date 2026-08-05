<?php

namespace Jayanta\NaturalQuery\Engine;

use Jayanta\NaturalQuery\Schema\SchemaRegistry;

/**
 * Suggest what to ask next.
 *
 * The hardest part of querying your own data in words is not phrasing a
 * question — it is knowing which questions the data can answer at all. A chat
 * interface makes that worse, because an empty prompt offers no clue what
 * exists. Every answer therefore carries a few concrete follow-ups.
 *
 * These are derived from the schema and the query that was just answered, not
 * from the model: no extra API call, no latency, no cost, and they cannot
 * suggest a breakdown the validator would refuse — the same groupable columns
 * that gate SQL generation gate these.
 *
 * PRIVACY: suggestions are built and returned locally. One kind of suggestion
 * ("Break West down by category") embeds a value from the result set, so it is
 * governed by `chat.suggest_drilldown_values`. Nothing here is ever sent to a
 * provider; if the user clicks such a suggestion, the resulting question is
 * their own query text, exactly as if they had typed it.
 */
class NextStepSuggester
{
    use Concerns\HumanizesNames;

    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param  array  $queryResult  The SqlBuilder result that produced the rows.
     * @param  array  $rows         The rows returned, used only for drill-down labels.
     * @return array<int, array{label: string, query: string}>
     */
    public function suggest(array $queryResult, array $rows = []): array
    {
        if (!config('naturalquery.chat.suggest_next_steps', true)) {
            return [];
        }

        $scheme = $queryResult['scheme'] ?? null;

        if (!$scheme || !$this->registry->has($scheme)) {
            return [];
        }

        $suggestions = [];

        foreach ([
            $this->drillIntoTopResult($queryResult, $rows),
            $this->otherBreakdowns($queryResult),
            $this->sameBreakdownOtherMetric($queryResult),
            $this->flipTheOrder($queryResult),
        ] as $group) {
            foreach ($group as $suggestion) {
                $suggestions[$suggestion['query']] = $suggestion;
            }
        }

        $max = (int) config('naturalquery.chat.max_next_steps', 4);

        return array_slice(array_values($suggestions), 0, max(0, $max));
    }

    /**
     * "West is the top region — break West down by category."
     *
     * The single most useful follow-up after a ranking, and the one a user is
     * least likely to phrase correctly unaided.
     */
    protected function drillIntoTopResult(array $queryResult, array $rows): array
    {
        if (empty($rows) || !config('naturalquery.chat.suggest_drilldown_values', true)) {
            return [];
        }

        // A group_value query is already a detail view; drilling again repeats it.
        if (!empty($queryResult['group_value'])) {
            return [];
        }

        $scheme = $queryResult['scheme'];
        $groupColumn = $queryResult['group_column'] ?? null;
        $top = (array) $rows[0];
        $label = $groupColumn !== null ? ($top[$groupColumn] ?? null) : null;

        if (!is_scalar($label) || trim((string) $label) === '') {
            return [];
        }

        $label = trim((string) $label);
        $metric = $queryResult['metric'] ?? '';
        $other = $this->otherDimensions($scheme, [$groupColumn]);

        if (empty($other)) {
            return [];
        }

        $by = $other[0];

        return [[
            'label' => "Break {$label} down by " . $this->humanize($by),
            'query' => trim("{$metric} by {$by} for {$label}"),
        ]];
    }

    /** The same measure, sliced a different way. */
    protected function otherBreakdowns(array $queryResult): array
    {
        $scheme = $queryResult['scheme'];
        $metric = $queryResult['metric'] ?? '';
        $out = [];

        foreach ($this->otherDimensions($scheme, [$queryResult['group_column'] ?? null]) as $dimension) {
            $out[] = [
                'label' => ucfirst($this->humanize($metric)) . ' by ' . $this->humanize($dimension),
                'query' => "{$metric} by {$dimension}",
            ];
        }

        return array_slice($out, 0, 2);
    }

    /**
     * The same slice, measured differently — usually "as a count", which is
     * the question people reach for immediately after seeing money.
     */
    protected function sameBreakdownOtherMetric(array $queryResult): array
    {
        $scheme = $queryResult['scheme'];
        $current = $queryResult['metric'] ?? '';
        $groupColumn = $queryResult['group_column'] ?? null;

        if (!$groupColumn) {
            return [];
        }

        $countMetric = SchemaRegistry::COUNT_METRIC;
        $metrics = array_column($this->registry->getSchemeMetrics($scheme), 'key');

        $candidates = [];

        if ($current !== $countMetric && in_array($countMetric, $metrics, true)) {
            $candidates[] = ['how many', $countMetric];
        }

        foreach ($metrics as $metric) {
            if (count($candidates) >= 2) {
                break;
            }
            if ($metric !== $current && $metric !== $countMetric) {
                $candidates[] = [$this->humanize($metric), $metric];
            }
        }

        $out = [];

        foreach ($candidates as [$word, $metric]) {
            $out[] = [
                'label' => 'Show ' . ($metric === $countMetric ? 'order counts' : $this->humanize($metric))
                    . ' by ' . $this->humanize($groupColumn),
                'query' => $metric === $countMetric
                    ? "how many by {$groupColumn}"
                    : "{$metric} by {$groupColumn}",
            ];
        }

        return array_slice($out, 0, 1);
    }

    /** Worst performers are as interesting as best, and rarely asked for. */
    protected function flipTheOrder(array $queryResult): array
    {
        if (($queryResult['query_type'] ?? '') !== 'ranking') {
            return [];
        }

        $order = strtoupper($queryResult['order'] ?? 'DESC');
        $metric = $queryResult['metric'] ?? '';
        $groupColumn = $queryResult['group_column'] ?? null;
        $limit = (int) ($queryResult['limit'] ?? 5);
        $limit = $limit > 0 ? min($limit, 10) : 5;

        $word = $order === 'DESC' ? 'bottom' : 'top';
        // "bottom 5 regions by revenue" — not "bottom 5 by region by revenue",
        // which reads badly and gives the intent parser two 'by' clauses to
        // disentangle.
        $noun = $groupColumn ? ' ' . $this->humanizePlural($groupColumn) : '';

        return [[
            'label' => ucfirst($word) . " {$limit} instead",
            'query' => trim("{$word} {$limit}{$noun} by {$metric}"),
        ]];
    }

    /**
     * Groupable columns other than the ones already used.
     *
     * @return array<int, string>
     */
    protected function otherDimensions(string $scheme, array $exclude): array
    {
        $exclude = array_filter($exclude);

        return array_values(array_filter(
            $this->registry->getGroupableColumns($scheme),
            fn ($c) => !in_array($c, $exclude, true)
        ));
    }

}
