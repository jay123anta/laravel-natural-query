<?php

namespace Jayanta\NaturalQuery\Engine;

/**
 * Response Formatter
 *
 * Formats query results into a structured response with:
 * - Display text (human-readable summary)
 * - Speech text (TTS-friendly version)
 * - Statistical insights
 * - Visualization type hint
 */
class ResponseFormatter
{
    use \Jayanta\NaturalQuery\Engine\Concerns\HumanizesNames;

    /**
     * Format a complete query response.
     */
    public function format(array $queryResult, array $rows, array $options = []): array
    {
        $responseType = $this->determineResponseType($queryResult, $rows);
        $texts = $this->generateAnswerText($queryResult, $rows, $responseType);
        $insights = $this->generateInsights($queryResult, $rows);
        $visualization = $this->determineVisualization($responseType, count($rows));

        $response = [
            'status' => 'success',
            'type' => $responseType,
            'rows' => $rows,
            'parsed_query' => [
                'scheme' => $queryResult['scheme'],
                'metric' => $queryResult['metric'],
                'group_value' => $queryResult['group_value'] ?? null,
                // The dimension the rows are actually broken down by. Exposed
                // so "revenue by region" answered as a customer ranking is
                // visible in the response instead of only in the row labels.
                'group_by' => $queryResult['group_column'] ?? null,
                // Which column group_value was matched against, when it is not
                // the one being grouped by. Without this in the response a
                // filtered ranking and an unfiltered one look identical.
                'filter_column' => $queryResult['filter_column'] ?? null,
                // Every column narrowed on, so a caller can tell a
                // two-filter answer from a one-filter one.
                'filter_columns' => $queryResult['filter_columns'] ?? [],
                // Paired, so the conversation can carry them forward without
                // guessing which value belongs to which column.
                'filters' => $queryResult['filters'] ?? [],
                // The period actually applied. "Last year" is read as calendar
                // 2025 by some models and as a trailing twelve months by
                // others, and the difference is invisible in the number alone.
                'period' => $queryResult['time_filter'] ?? null,
                'limit' => $queryResult['limit'] ?? null,
                'order' => $queryResult['order'] ?? null,
                'query_type' => $queryResult['query_type'] ?? 'ranking',
            ],
            'answer' => $texts['display'],
            'visualization' => $visualization,
        ];

        if (config('naturalquery.response.include_speech_text', true)) {
            $response['speech_text'] = $texts['speech'];
        }

        if (config('naturalquery.response.include_insights', true)) {
            $response['insights'] = $insights;
        }

        if (config('naturalquery.response.include_visualization_hint', true)) {
            $response['visualization'] = $visualization;
        }

        return $response;
    }

    /**
     * Format a "no data" response.
     */
    public function formatNoData(array $queryResult): array
    {
        $groupValue = $queryResult['group_value'] ?? null;
        $schemeName = $queryResult['scheme_name'] ?? $queryResult['scheme'];

        $message = $groupValue
            ? "No data found for {$groupValue} in {$schemeName}. The name may be spelled differently in the database."
            : "No data found for {$schemeName}.";

        return [
            'status' => 'success',
            'type' => 'no_data',
            'rows' => [],
            'parsed_query' => [
                'scheme' => $queryResult['scheme'],
                'metric' => $queryResult['metric'],
                'group_value' => $groupValue,
            ],
            'answer' => $message,
            'visualization' => 'message',
        ];
    }

    /**
     * Format a clarification response.
     */
    public function formatClarification(array $intent, array $availableSchemes, array $availableMetrics = []): array
    {
        $rawType = $intent['clarification_type'] ?? 'scheme';
        $clarificationType = in_array($rawType, ['scheme', 'scheme_clarification'])
            ? 'scheme_clarification'
            : 'metric_clarification';

        // Dataset choices belong on a dataset question and nowhere else.
        //
        // They were sent on every clarification, so a metric question rendered
        // the dataset name as an extra button among the metrics — "Orders"
        // sitting beside "Quantity" and "Revenue". Clicking it re-sent the same
        // question with the scheme it already had, got the same response, and
        // redrew the same card: a button that looks broken because there is
        // nothing for it to do.
        $alternatives = $clarificationType === 'scheme_clarification'
            ? array_map(fn ($s) => [
                'scheme_name' => $s['name'],
                'scheme_key' => $s['key'],
                'confidence' => 0.5,
            ], $availableSchemes)
            : [];

        return [
            'status' => 'clarification_needed',
            'type' => $clarificationType,
            'message' => $clarificationType === 'metric_clarification'
                ? 'What metric would you like to see for this dataset?'
                : 'Which dataset would you like to query? Please select from the options.',
            // Null-coalesced on purpose: a provider is only required to return
            // the keys it actually resolved. A self-hosted or OpenAI-compatible
            // model that omits 'district' must still produce a clarification,
            // not an undefined-key error that the orchestrator turns into a
            // generic failure. Provider portability matters more than strict
            // response shapes here.
            'parsed_query' => [
                'scheme' => $intent['scheme'] ?? null,
                'metric' => $intent['metric'] ?? null,
                'group_value' => $intent['group_value'] ?? null,
            ],
            'alternatives' => $alternatives,
            'available_metrics' => $availableMetrics,
            'metadata' => [
                'confidence' => $intent['confidence'] ?? 0,
            ],
        ];
    }

    /**
     * Format an error response.
     */
    /**
     * @param string $code Machine-readable reason — see ErrorCode.
     */
    public function formatError(string $error, array $metadata = [], string $code = ErrorCode::INTERNAL): array
    {
        return [
            'status' => 'error',
            // The message is for a person and will be reworded. The code is for
            // a client and will not: a frontend deciding whether to retry, ask
            // again, or show a support link should never have to match on
            // English prose.
            'error_code' => $code,
            'error' => $error,
            'metadata' => $metadata,
        ];
    }

    /**
     * Determine the response type based on query result and rows.
     */
    protected function determineResponseType(array $queryResult, array $rows): string
    {
        $queryType = $queryResult['query_type'] ?? 'ranking';

        if ($queryType === 'group_detail' || !empty($queryResult['group_value'])) {
            return count($rows) === 1 ? 'single_result' : 'ranking';
        }

        if ($queryType === 'aggregation') {
            return 'aggregation';
        }

        return count($rows) === 1 ? 'single_result' : 'ranking';
    }

    /**
     * Work out what to call a row.
     *
     * The schema's group_column is the intended label, but the query does not
     * always return it — a model that groups by something else, or joins and
     * aliases the column, produces rows without it. Emitting "?" for every row
     * made an otherwise correct answer look broken, so fall back to the first
     * value in the row that reads like a label rather than a number.
     *
     * @param array<string, mixed> $row
     */
    protected function labelFor(array $row, string $groupColumn, string $metric): string
    {
        if (isset($row[$groupColumn]) && $row[$groupColumn] !== null && $row[$groupColumn] !== '') {
            return (string) $row[$groupColumn];
        }

        foreach ($row as $column => $value) {
            if ($column === $metric || $value === null || $value === '') {
                continue;
            }

            if (!is_numeric($value)) {
                return (string) $value;
            }
        }

        // Everything in the row is a number: an id is still a better label
        // than a question mark.
        foreach ($row as $column => $value) {
            if ($column !== $metric && $value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return '—';
    }

    /**
     * Generate answer text (both display and speech versions).
     */
    protected function generateAnswerText(array $queryResult, array $rows, string $type): array
    {
        $schemeName = $queryResult['scheme_name'] ?? $queryResult['scheme'];
        $metric = $queryResult['metric'] ?? 'data';
        $metricDesc = $queryResult['metric_description'] ?? $metric;
        $unit = $queryResult['metric_unit'] ?? '';
        $groupColumn = $queryResult['group_column'] ?? 'name';
        $order = strtolower($queryResult['order'] ?? 'desc');
        $count = count($rows);
        $numberFormat = config('naturalquery.response.number_format', 'international');

        if ($type === 'single_result' && $count === 1) {
            $row = (array) $rows[0];
            $name = $this->labelFor($row, $groupColumn, $metric);
            $value = $row[$metric] ?? 'N/A';
            $formattedValue = is_numeric($value) ? $this->formatNumber($value, $numberFormat) : $value;

            return [
                'display' => "{$name}: {$formattedValue} {$unit} ({$metricDesc})",
                'speech' => "{$name} has {$formattedValue} {$unit} for {$metricDesc} in {$schemeName}.",
            ];
        }

        if ($type === 'aggregation') {
            $row = (array) $rows[0];
            $totalKey = "total_{$metric}";
            $total = $row[$totalKey] ?? array_values($row)[0] ?? 0;
            $formatted = $this->formatNumber($total, $numberFormat);

            return [
                'display' => "Total {$metricDesc}: {$formatted} {$unit}",
                'speech' => "The total {$metricDesc} for {$schemeName} is {$formatted} {$unit}.",
            ];
        }

        // Ranking
        $direction = $order === 'desc' ? 'highest' : 'lowest';
        $topRows = array_slice($rows, 0, 3);
        $topNames = array_map(fn ($r) => $this->labelFor((array) $r, $groupColumn, $metric), $topRows);
        $topList = implode(', ', $topNames);

        // Naming the dimension is what tells the reader which question was
        // answered. "Top 5 by revenue: West, Central" reads the same whether
        // the rows are regions or customers.
        $noun = $this->humanizeDimension($groupColumn);

        return [
            'display' => "Top {$count} {$noun} by {$metricDesc} ({$direction}): {$topList}" . ($count > 3 ? '...' : ''),
            'speech' => "Here are the {$count} {$noun} with the {$direction} {$metricDesc} in {$schemeName}. Top entries are {$topList}.",
        ];
    }

    /** @see HumanizesNames::humanizePlural() */
    protected function humanizeDimension(string $column): string
    {
        return $this->humanizePlural($column);
    }

    /**
     * Generate statistical insights from results.
     */
    protected function generateInsights(array $queryResult, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $metric = $queryResult['metric'] ?? null;
        if (!$metric) {
            return [];
        }

        $values = array_filter(
            array_map(fn($r) => ((array) $r)[$metric] ?? null, $rows),
            fn($v) => is_numeric($v)
        );

        if (empty($values)) {
            return [];
        }

        $numberFormat = config('naturalquery.response.number_format', 'international');

        return [
            'count' => count($values),
            'total' => $this->formatNumber(array_sum($values), $numberFormat),
            'average' => $this->formatNumber(array_sum($values) / count($values), $numberFormat),
            'min' => $this->formatNumber(min($values), $numberFormat),
            'max' => $this->formatNumber(max($values), $numberFormat),
        ];
    }

    /**
     * Determine the best visualization type.
     */
    protected function determineVisualization(string $responseType, int $rowCount): string
    {
        if ($responseType === 'single_result') {
            return 'card';
        }

        if ($responseType === 'aggregation') {
            return 'card';
        }

        if ($rowCount <= 5) {
            return 'bar';
        }

        if ($rowCount <= 20) {
            return 'bar';
        }

        return 'table';
    }

    /**
     * Format a number according to the configured style.
     */
    protected function formatNumber($value, string $format = 'international'): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $value = floatval($value);

        // Detect if it's a whole number
        if (floor($value) == $value && $value < PHP_INT_MAX) {
            if ($format === 'indian') {
                return $this->formatIndian((int) $value);
            }
            return number_format((int) $value);
        }

        if ($format === 'indian') {
            return $this->formatIndian($value, 2);
        }

        return number_format($value, 2);
    }

    /**
     * Format number in Indian numbering system (12,34,567).
     */
    protected function formatIndian($number, int $decimals = 0): string
    {
        $negative = $number < 0;
        $number = abs($number);

        $parts = explode('.', number_format($number, $decimals, '.', ''));
        $integer = $parts[0];
        $decimal = $parts[1] ?? '';

        // Apply Indian grouping
        if (strlen($integer) > 3) {
            $lastThree = substr($integer, -3);
            $remaining = substr($integer, 0, -3);
            $remaining = preg_replace('/(\d)(?=(\d{2})+$)/', '$1,', $remaining);
            $integer = $remaining . ',' . $lastThree;
        }

        return ($negative ? '-' : '') . $integer . ($decimal ? '.' . $decimal : '');
    }
}
