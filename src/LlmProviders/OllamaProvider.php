<?php

namespace Jayanta\NaturalQuery\LlmProviders;

use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Exceptions\UnsupportedFeatureException;
use Illuminate\Support\Facades\Http;

/**
 * Ollama LLM Provider (local/self-hosted models)
 *
 * Runs entirely locally - no data ever leaves the user's machine.
 * Supports any model available in Ollama (llama3, mistral, codellama, etc.)
 */
class OllamaProvider extends AbstractProvider implements LlmProviderInterface
{
    protected string $baseUrl;
    protected string $model;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->baseUrl = rtrim($config['base_url'] ?? 'http://localhost:11434', '/');
        $this->model = $config['model'] ?? 'llama3';
    }

    public function generateSql(string $prompt): array
    {
        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'temperature' => 0.1,
                'num_predict' => 512,
            ],
        ];

        $response = $this->callWithRetry("{$this->baseUrl}/api/generate", $payload);

        if (!$response['success']) {
            return $response;
        }

        $text = $response['data']['response'] ?? '';
        $parsed = $this->parseJsonResponse($text);

        if (!$parsed) {
            return ['success' => false, 'error' => 'Invalid JSON response from Ollama'];
        }

        return ['success' => true, 'data' => $parsed];
    }

    public function parseIntent(string $text, array $schemeList): array
    {
        $schemeInfo = $this->buildSchemeInfo($schemeList);
        $today = $this->today();

        $prompt = <<<PROMPT
Parse this query: "{$text}"

DATASETS:
{$schemeInfo}

query_type = "aggregation" for one number over the whole dataset ("total revenue", "how many orders"), else "ranking".
group_by = the column to break results down by if the user asked ("revenue BY REGION"), from that dataset's group_by list, else null.
metric = record_count when the user asks HOW MANY ("how many orders", "orders by status"), otherwise the named measure.
date_from / date_to = YYYY-MM-DD dates if the user named a period ("last month", "in 2025"), else null. TODAY IS {$today}.

Return JSON: {"scheme":"key","metric":"name","limit":10,"order":"desc","query_type":"ranking","group_value":null,"group_by":null,"date_from":null,"date_to":null,"confidence":0.85,"needs_clarification":false,"clarification_type":null}
PROMPT;

        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'temperature' => 0.1,
                'num_predict' => 256,
            ],
        ];

        $response = $this->callWithRetry("{$this->baseUrl}/api/generate", $payload);

        if (!$response['success']) {
            return $this->errorResponse($response['error'], $response['status'] ?? null);
        }

        $responseText = $response['data']['response'] ?? '';
        $parsed = $this->parseJsonResponse($responseText);

        if (!$parsed) {
            return $this->errorResponse('Failed to parse intent response');
        }

        return $this->normalizeIntent($parsed, $schemeList);
    }

    public function parseVoiceQuery(string $audioBase64, string $mimeType, array $schemeList): array
    {
        throw UnsupportedFeatureException::voiceNotSupported('ollama');
    }

    public function healthCheck(): array
    {
        if (static::$healthCache !== null && static::$healthCacheTime !== null) {
            if ((time() - static::$healthCacheTime) < static::HEALTH_CACHE_TTL) {
                return static::$healthCache;
            }
        }

        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");

            $result = $response->successful()
                ? ['status' => 'ok', 'model' => $this->model]
                : ['status' => 'error', 'message' => 'Ollama not reachable'];

            static::$healthCache = $result;
            static::$healthCacheTime = time();

            return $result;
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Ollama not running at ' . $this->baseUrl];
        }
    }

    public function getName(): string
    {
        return 'ollama';
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
            'query_type' => $parsed['query_type'] ?? null,
            'date_from' => $parsed['date_from'] ?? null,
            'date_to' => $parsed['date_to'] ?? null,
            'confidence' => $confidence,
            'needs_clarification' => $parsed['needs_clarification'] ?? ($confidence < 0.7),
            'clarification_type' => $parsed['clarification_type'] ?? null,
        ];
    }
}
