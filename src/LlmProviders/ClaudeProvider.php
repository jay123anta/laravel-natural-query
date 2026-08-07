<?php

namespace Jayanta\NaturalQuery\LlmProviders;

use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Exceptions\UnsupportedFeatureException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Claude LLM Provider
 *
 * Text-only provider. AI receives ONLY schema structure, never actual data.
 */
class ClaudeProvider extends AbstractProvider implements LlmProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'claude-sonnet-4-20250514';
    }

    public function generateSql(string $prompt): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => 512,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
                // Prefill assistant response with { to force JSON output
                ['role' => 'assistant', 'content' => '{'],
            ],
            'system' => 'You are a SQL query generator. You MUST respond with valid JSON only. No markdown, no explanation, no code blocks. Start your response with { and end with }.',
            'temperature' => 0.1,
        ];

        $response = $this->callWithRetry(
            "{$this->baseUrl}/messages",
            $payload,
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ]
        );

        if (!$response['success']) {
            return $response;
        }

        $text = $response['data']['content'][0]['text'] ?? '';
        // Prepend { since we used prefilled assistant response
        $text = '{' . $text;
        $parsed = $this->parseJsonResponse($text);

        if (!$parsed) {
            return ['success' => false, 'error' => 'Invalid JSON response from Claude'];
        }

        return ['success' => true, 'data' => $parsed];
    }

    public function parseIntent(string $text, array $schemeList): array
    {
        $schemeInfo = $this->buildSchemeInfo($schemeList);
        $today = $this->today();

        $prompt = <<<PROMPT
Parse this natural language query about datasets: "{$text}"

AVAILABLE DATASETS:
{$schemeInfo}

Extract: scheme (dataset key), metric, limit (default 10), order (asc/desc), group_value (or null), group_by (or null), confidence (0.0-1.0).

When the user asks HOW MANY — "how many orders", "number of tickets", "orders by status" — the metric is record_count. Only pick a money or quantity metric when the user names one.

query_type: "aggregation" when the user wants ONE number for the whole dataset ("total revenue", "how many orders are there") with no breakdown and no named record; "ranking" for a list, which is the usual case; "group_detail" when they named one record.

filters: the COMPLETE list of column filters in force after applying the instruction, as [{"column":"region","value":"East"}]. Repeat the ones that still apply — a filter you leave out is switched off. Use this whenever more than one filter applies, or when correcting one of several.

filter_column: the column that group_value belongs to, when it is NOT the column being grouped by. "quantity by customer_name where product_category is Grocery" has group_by=customer_name, group_value=Grocery, filter_column=product_category. Leave null when the filter is on the grouping column itself.

group_by is the column to break results down by when the user asks for one — "revenue BY REGION", "orders PER STATUS". It must be one of that dataset's group_by columns; use null when no breakdown is named.

date_from / date_to: if the user named a period — "last month", "in 2025", "since April" — resolve it to YYYY-MM-DD dates using TODAY'S DATE: {$today}. Both null when no period is mentioned. Never invent a period the user did not ask for.

If unclear, set needs_clarification=true with clarification_type="scheme" or "metric".
Never set needs_clarification for a HOW MANY question. Every dataset has record_count, so "how many X are there" is always answerable without asking.
If the user asks for a breakdown that is NOT in that dataset's group_by list, set needs_clarification=true and clarification_type="ambiguous" — never fall back to the default breakdown.

Return JSON only: {"scheme":"key","metric":"name","limit":10,"order":"desc","query_type":"ranking","group_value":null,"filter_column":null,"filters":[],"group_by":null,"date_from":null,"date_to":null,"confidence":0.85,"needs_clarification":false,"clarification_type":null}
PROMPT;

        $payload = [
            'model' => $this->model,
            'max_tokens' => 256,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
                ['role' => 'assistant', 'content' => '{'],
            ],
            'system' => 'You parse natural language queries into structured intent. You MUST respond with valid JSON only. No markdown, no explanation. Start with { and end with }.',
            'temperature' => 0.1,
        ];

        $response = $this->callWithRetry(
            "{$this->baseUrl}/messages",
            $payload,
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ]
        );

        if (!$response['success']) {
            return $this->errorResponse($response['error'], $response['status'] ?? null);
        }

        $responseText = $response['data']['content'][0]['text'] ?? '';
        $responseText = '{' . $responseText;
        $parsed = $this->parseJsonResponse($responseText);

        if (!$parsed) {
            return $this->errorResponse('Failed to parse intent response');
        }

        return $this->normalizeIntent($parsed, $schemeList);
    }

    public function parseVoiceQuery(string $audioBase64, string $mimeType, array $schemeList): array
    {
        throw UnsupportedFeatureException::voiceNotSupported('claude');
    }

    public function healthCheck(): array
    {
        if (empty($this->apiKey)) {
            return ['status' => 'error', 'message' => 'API key not configured'];
        }

        // Claude doesn't have a simple model info endpoint, so we cache aggressively
        if (static::$healthCache !== null && static::$healthCacheTime !== null) {
            if ((time() - static::$healthCacheTime) < static::HEALTH_CACHE_TTL) {
                return static::$healthCache;
            }
        }

        try {
            // Minimal API call to verify key works
            $request = Http::timeout(10)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ]);

            if ($sslOptions = $this->sslOptions()) {
                $request = $request->withOptions($sslOptions);
            }

            $response = $request->post("{$this->baseUrl}/messages", [
                'model' => $this->model,
                'max_tokens' => 10,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);

            $result = $response->successful()
                ? ['status' => 'ok', 'model' => $this->model]
                : ['status' => 'error', 'message' => 'API returned ' . $response->status()];

            static::$healthCache = $result;
            static::$healthCacheTime = time();

            return $result;
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $this->sanitizeError($e->getMessage())];
        }
    }

    public function getName(): string
    {
        return 'claude';
    }

    public function supportsVoice(): bool
    {
        return false;
    }

    protected function normalizeIntent(array $parsed, array $schemeList): array
    {
        $scheme = $parsed['scheme'] ?? null;
        if ($scheme) {
            $validKeys = array_column($schemeList, 'key');
            if (!in_array($scheme, $validKeys)) {
                $scheme = null;
            }
        }

        $confidence = max(0.0, min(1.0, floatval($parsed['confidence'] ?? 0.5)));

        return [
            'success' => true,
            'scheme' => $scheme,
            'metric' => $parsed['metric'] ?? null,
            'limit' => min(max(intval($parsed['limit'] ?? 10), 1), config('naturalquery.sql.max_limit') ?? 1000),
            'order' => in_array(strtolower($parsed['order'] ?? 'desc'), ['asc', 'desc']) ? strtolower($parsed['order']) : 'desc',
            'group_value' => $parsed['group_value'] ?? null,
            'group_by' => $parsed['group_by'] ?? null,
            'filter_column' => $parsed['filter_column'] ?? null,
            'filters' => is_array($parsed['filters'] ?? null) ? $parsed['filters'] : [],
            'query_type' => $parsed['query_type'] ?? null,
            'date_from' => $parsed['date_from'] ?? null,
            'date_to' => $parsed['date_to'] ?? null,
            'confidence' => $confidence,
            'needs_clarification' => $parsed['needs_clarification'] ?? ($confidence < 0.7),
            'clarification_type' => $parsed['clarification_type'] ?? null,
        ];
    }
}
