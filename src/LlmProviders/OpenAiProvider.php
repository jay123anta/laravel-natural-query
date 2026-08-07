<?php

namespace Jayanta\NaturalQuery\LlmProviders;

use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Exceptions\UnsupportedFeatureException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible LLM Provider.
 *
 * Works with OpenAI itself (GPT-4o family) AND with ANY service speaking the
 * OpenAI chat-completions protocol — which is the de-facto standard:
 *
 *   Hosted:      DeepSeek, Groq, Mistral, Together, OpenRouter, …
 *   Self-hosted: vLLM, LM Studio, LocalAI, llama.cpp server, Ollama
 *                (via its /v1 endpoint), text-generation-webui, …
 *
 * Point `base_url` at the server, set `model`, and (optionally) `api_key`.
 * Self-hosted servers frequently need no key and may not support
 * `response_format` — set `force_json => false` for those.
 *
 * Text-only provider. AI receives ONLY schema structure, never actual data.
 */
class OpenAiProvider extends AbstractProvider implements LlmProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;
    protected string $providerName;
    protected int $maxTokens;
    protected bool $forceJson;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4o-mini';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        // Shown in logs/health output — lets a DeepSeek/vLLM setup identify
        // itself instead of appearing as "openai".
        $this->providerName = $config['name'] ?? 'openai';
        $this->maxTokens = (int) ($config['max_tokens'] ?? 2048);
        // OpenAI's structured-output flag. Most compatible servers accept it,
        // but some self-hosted stacks reject unknown params — disable there.
        $this->forceJson = (bool) ($config['force_json'] ?? true);
    }

    /** Bearer header only when a key is configured (self-hosted is often keyless). */
    protected function authHeaders(): array
    {
        return $this->apiKey !== '' ? ['Authorization' => "Bearer {$this->apiKey}"] : [];
    }

    /** Apply response_format only when enabled. */
    protected function withJsonFormat(array $payload): array
    {
        if ($this->forceJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        return $payload;
    }

    public function generateSql(string $prompt): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a SQL query generator. Respond with JSON only, no markdown.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => $this->maxTokens,
        ];
        $payload = $this->withJsonFormat($payload);

        $response = $this->callWithRetry(
            "{$this->baseUrl}/chat/completions",
            $payload,
            $this->authHeaders()
        );

        if (!$response['success']) {
            return $response;
        }

        $text = $response['data']['choices'][0]['message']['content'] ?? '';
        $parsed = $this->parseJsonResponse($text);

        if (!$parsed) {
            return ['success' => false, 'error' => 'Invalid JSON response from OpenAI'];
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

Periods are CALENDAR periods unless the user says otherwise: "this year" is 1 January to 31 December of the current year, "last year" the whole of the year before, "last month" the previous calendar month, "last quarter" the previous calendar quarter. Never a rolling window ending today — comparing a calendar year against a trailing twelve months puts overlapping data on both sides and the difference is meaningless.
date_from / date_to: if the user named a period — "last month", "in 2025", "since April" — resolve it to YYYY-MM-DD dates using TODAY'S DATE: {$today}. Both null when no period is mentioned. Never invent a period the user did not ask for.

If unclear, set needs_clarification=true with clarification_type="scheme" or "metric".
Never set needs_clarification for a HOW MANY question. Every dataset has record_count, so "how many X are there" is always answerable without asking.
If the user asks for a breakdown that is NOT in that dataset's group_by list, set needs_clarification=true and clarification_type="ambiguous" — never fall back to the default breakdown.

Return JSON: {"scheme":"key","metric":"name","limit":10,"order":"desc","query_type":"ranking","group_value":null,"filter_column":null,"filters":[],"group_by":null,"date_from":null,"date_to":null,"confidence":0.85,"needs_clarification":false,"clarification_type":null}
PROMPT;

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You parse natural language queries into structured intent. Respond with JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 1024,
        ];
        $payload = $this->withJsonFormat($payload);

        $response = $this->callWithRetry(
            "{$this->baseUrl}/chat/completions",
            $payload,
            $this->authHeaders()
        );

        if (!$response['success']) {
            return $this->errorResponse($response['error'], $response['status'] ?? null);
        }

        $responseText = $response['data']['choices'][0]['message']['content'] ?? '';
        $parsed = $this->parseJsonResponse($responseText);

        if (!$parsed) {
            return $this->errorResponse('Failed to parse intent response');
        }

        return $this->normalizeIntent($parsed, $schemeList);
    }

    public function parseVoiceQuery(string $audioBase64, string $mimeType, array $schemeList): array
    {
        throw UnsupportedFeatureException::voiceNotSupported($this->providerName);
    }

    public function healthCheck(): array
    {
        // Self-hosted OpenAI-compatible servers are often keyless — only
        // require a key when talking to api.openai.com itself.
        if (empty($this->apiKey) && str_contains($this->baseUrl, 'api.openai.com')) {
            return ['status' => 'error', 'message' => 'API key not configured'];
        }

        if (static::$healthCache !== null && static::$healthCacheTime !== null) {
            if ((time() - static::$healthCacheTime) < static::HEALTH_CACHE_TTL) {
                return static::$healthCache;
            }
        }

        try {
            $request = Http::timeout(10)
                ->withHeaders($this->authHeaders());

            if ($sslOptions = $this->sslOptions()) {
                $request = $request->withOptions($sslOptions);
            }

            $response = $request->get("{$this->baseUrl}/models/{$this->model}");

            // Some OpenAI-compatible servers don't implement /models/{id};
            // fall back to the /models list before declaring failure.
            if (!$response->successful() && $response->status() === 404) {
                $response = $request->get("{$this->baseUrl}/models");
            }

            $result = $response->successful()
                ? ['status' => 'ok', 'model' => $this->model, 'provider' => $this->providerName]
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
        return $this->providerName;
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
