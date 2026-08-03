<?php

namespace Jayanta\NaturalQuery\LlmProviders;

use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini LLM Provider
 *
 * Supports both text and voice (audio) input.
 * AI receives ONLY schema structure, never actual data.
 */
class GeminiProvider extends AbstractProvider implements LlmProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Thinking budget for Gemini 2.5+ models. 0 = thinking disabled (default —
     * SQL/intent extraction at low temperature does not need extended
     * reasoning). Without this, 2.5-model "thinking" tokens consume
     * maxOutputTokens internally and TRUNCATE the JSON answer mid-response.
     * Older models (2.0-flash) silently ignore thinkingConfig.
     * null = omit thinkingConfig entirely (provider default behavior).
     */
    protected ?int $thinkingBudget;

    /** Output-token ceiling for SQL generation responses. */
    protected int $maxOutputTokens;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->apiKey = $config['api_key'] ?? '';
        // NOTE: gemini-2.0-flash was RETIRED by Google (returns HTTP 404).
        // Default must be a live model.
        $this->model = $config['model'] ?? 'gemini-2.5-flash';
        $this->thinkingBudget = array_key_exists('thinking_budget', $config)
            ? ($config['thinking_budget'] === null ? null : (int) $config['thinking_budget'])
            : 0;
        $this->maxOutputTokens = (int) ($config['max_output_tokens'] ?? 2048);
    }

    /**
     * Build generationConfig with the thinking-budget guard applied.
     */
    protected function generationConfig(int $maxTokens, bool $forceJson = true): array
    {
        $config = [
            'temperature' => 0.1,
            'topP' => 0.8,
            'topK' => 40,
            'maxOutputTokens' => $maxTokens,
        ];
        if ($forceJson) {
            $config['responseMimeType'] = 'application/json';
        }
        if ($this->thinkingBudget !== null) {
            $config['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget];
        }
        return $config;
    }

    public function generateSql(string $prompt): array
    {
        $endpoint = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => $this->generationConfig($this->maxOutputTokens),
        ];

        $response = $this->callWithRetry($endpoint, $payload);

        if (!$response['success']) {
            return $response;
        }

        $text = $response['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed = $this->parseJsonResponse($text);

        if (!$parsed) {
            return ['success' => false, 'error' => 'Invalid JSON response from Gemini'];
        }

        return ['success' => true, 'data' => $parsed];
    }

    public function parseIntent(string $text, array $schemeList): array
    {
        $schemeInfo = $this->buildSchemeInfo($schemeList);
        $prompt = $this->buildIntentPrompt($text, $schemeInfo);

        $endpoint = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";
        $payload = [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => $this->generationConfig(1024),
        ];

        $response = $this->callWithRetry($endpoint, $payload);

        if (!$response['success']) {
            return $this->errorResponse($response['error'], $response['status'] ?? null);
        }

        $text = $response['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed = $this->parseJsonResponse($text);

        if (!$parsed) {
            return $this->errorResponse('Failed to parse intent response');
        }

        return $this->normalizeIntent($parsed, $schemeList);
    }

    public function parseVoiceQuery(string $audioBase64, string $mimeType, array $schemeList): array
    {
        $schemeInfo = $this->buildSchemeInfo($schemeList);
        $prompt = $this->buildVoicePrompt($schemeInfo);

        $endpoint = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";
        $payload = [
            'contents' => [[
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $audioBase64,
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => $this->generationConfig(1024, false),
        ];

        $response = $this->callWithRetry($endpoint, $payload);

        if (!$response['success']) {
            return $this->errorResponse($response['error'], $response['status'] ?? null);
        }

        $text = $response['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed = $this->parseJsonResponse($text);

        if (!$parsed) {
            return $this->errorResponse('Failed to parse voice response');
        }

        return $this->normalizeIntent($parsed, $schemeList);
    }

    public function healthCheck(): array
    {
        if (empty($this->apiKey)) {
            return ['status' => 'error', 'message' => 'API key not configured'];
        }

        if (static::$healthCache !== null && static::$healthCacheTime !== null) {
            if ((time() - static::$healthCacheTime) < static::HEALTH_CACHE_TTL) {
                return static::$healthCache;
            }
        }

        try {
            $request = Http::timeout(10);

            $sslOptions = $this->sslOptions();
            if (($sslOptions['verify'] ?? null) === false) {
                $sslOptions['force_ip_resolve'] = 'v4';
            }
            if ($sslOptions) {
                $request = $request->withOptions($sslOptions);
            }

            $response = $request->get("{$this->baseUrl}/models/{$this->model}?key={$this->apiKey}");

            if ($response->successful()) {
                $result = ['status' => 'ok', 'model' => $this->model];
            } elseif ($response->status() === 429) {
                $result = static::$healthCache ?? ['status' => 'ok', 'model' => $this->model, 'cached' => true];
            } else {
                $result = ['status' => 'error', 'message' => 'API returned ' . $response->status()];
            }

            static::$healthCache = $result;
            static::$healthCacheTime = time();

            return $result;
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $this->sanitizeError($e->getMessage())];
        }
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function supportsVoice(): bool
    {
        return true;
    }

    /**
     * Build scheme info string for prompts.
     */
    protected function buildSchemeInfo(array $schemeList): string
    {
        $lines = [];
        foreach ($schemeList as $scheme) {
            $key = $scheme['key'];
            $name = $scheme['name'];
            $aliases = implode(', ', array_slice($scheme['aliases'] ?? [], 0, 3));
            $metrics = implode(', ', array_keys($scheme['metrics'] ?? []));
            $lines[] = "- {$key} ({$name}): aliases=[{$aliases}], metrics=[{$metrics}]";
        }
        return implode("\n", $lines);
    }

    /**
     * Build the intent parsing prompt for text input.
     */
    protected function buildIntentPrompt(string $queryText, string $schemeInfo): string
    {
        return <<<PROMPT
Parse this natural language query about datasets: "{$queryText}"

AVAILABLE DATASETS WITH THEIR METRICS:
{$schemeInfo}

TASK: Extract:
1. scheme: One of the available dataset keys
2. metric: What measurement the user wants (use exact metric names)
3. limit: Number of results requested (default 10)
4. order: "desc" for highest/top/most or "asc" for lowest/bottom/least
5. group_value: A specific record name to filter to, if the user named one (or null). Never the name of a category or column.
6. confidence: Your confidence 0.0 to 1.0

IMPORTANT RULES:
- Match the user's query to the correct dataset key
- If dataset is unclear, set needs_clarification=true and clarification_type="scheme"
- If metric is unclear for identified dataset, set needs_clarification=true and clarification_type="metric"

RETURN FORMAT (JSON only, no markdown):
{
    "scheme": "dataset_key or null",
    "metric": "metric_name or null",
    "limit": 10,
    "order": "desc",
    "group_value": null,
    "confidence": 0.85,
    "needs_clarification": false,
    "clarification_type": null
}

RESPOND WITH JSON ONLY:
PROMPT;
    }

    /**
     * Build the voice/audio prompt.
     */
    protected function buildVoicePrompt(string $schemeInfo): string
    {
        return <<<PROMPT
Listen to the audio query about datasets. Extract the user's intent and return ONLY valid JSON.

AVAILABLE DATASETS WITH THEIR METRICS:
{$schemeInfo}

TASK: Extract from the audio:
1. scheme: One of the available dataset keys
2. metric: What measurement the user wants
3. limit: Number of results requested (default 10)
4. order: "desc" for highest/top/most or "asc" for lowest/bottom/least
5. group_value: A specific record name to filter to, if the user named one (or null). Never the name of a category or column.
6. confidence: Your confidence 0.0 to 1.0

RETURN FORMAT (JSON only, no markdown):
{
    "transcribed_text": "what you heard",
    "scheme": "dataset_key or null",
    "metric": "metric_name or null",
    "limit": 10,
    "order": "desc",
    "group_value": null,
    "confidence": 0.85,
    "needs_clarification": false,
    "clarification_type": null
}

If scheme is unclear, set needs_clarification=true and clarification_type="scheme".
If metric is unclear, set needs_clarification=true and clarification_type="metric".

RESPOND WITH JSON ONLY:
PROMPT;
    }

    /**
     * Normalize and validate parsed intent.
     */
    protected function normalizeIntent(array $parsed, array $schemeList): array
    {
        $scheme = $parsed['scheme'] ?? null;

        // Validate scheme exists
        if ($scheme) {
            $validSchemes = array_column($schemeList, 'key');
            if (!in_array($scheme, $validSchemes)) {
                $scheme = $this->findSchemeByAlias($scheme, $schemeList);
            }
        }

        $limit = min(max(intval($parsed['limit'] ?? 10), 1), config('naturalquery.sql.max_limit') ?? 1000);
        $order = strtolower($parsed['order'] ?? 'desc');
        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'desc';
        }

        $confidence = max(0.0, min(1.0, floatval($parsed['confidence'] ?? 0.5)));

        return [
            'success' => true,
            'transcribed_text' => $parsed['transcribed_text'] ?? null,
            'scheme' => $scheme,
            'metric' => $parsed['metric'] ?? null,
            'limit' => $limit,
            'order' => $order,
            'group_value' => $parsed['group_value'] ?? null,
            'confidence' => $confidence,
            'needs_clarification' => $parsed['needs_clarification'] ?? ($confidence < 0.7),
            'clarification_type' => $parsed['clarification_type'] ?? null,
        ];
    }

    /**
     * Find scheme by alias match.
     */
    protected function findSchemeByAlias(string $input, array $schemeList): ?string
    {
        $input = strtolower(trim($input));

        foreach ($schemeList as $scheme) {
            if (strtolower($scheme['key']) === $input) {
                return $scheme['key'];
            }
            foreach ($scheme['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $input) {
                    return $scheme['key'];
                }
            }
        }

        return null;
    }
}
