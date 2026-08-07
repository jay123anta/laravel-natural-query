<?php

namespace Jayanta\NaturalQuery\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Jayanta\NaturalQuery\Engine\ErrorCode;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Conversation\ConversationManager;
use Jayanta\NaturalQuery\Feedback\FeedbackStore;
use Illuminate\Support\Str;

/**
 * NaturalQuery HTTP Controller
 *
 * Provides API endpoints for the NaturalQuery package:
 * - Text query processing
 * - Voice query processing
 * - Health check
 * - Scheme listing
 * - Cache management
 */
class NaturalQueryController extends Controller
{
    protected QueryOrchestrator $orchestrator;
    protected ConversationManager $conversation;
    protected FeedbackStore $feedback;

    public function __construct(QueryOrchestrator $orchestrator, ConversationManager $conversation, FeedbackStore $feedback)
    {
        $this->orchestrator = $orchestrator;
        $this->conversation = $conversation;
        $this->feedback = $feedback;
    }

    /**
     * Send a result with an HTTP status that matches what went wrong.
     *
     * Every failure used to arrive as 400 or 500 chosen by whether a metadata
     * key happened to be set, so a client could not tell "your question was
     * refused" from "the provider is rate limiting" from "the database is
     * down" — and the one sensible response to a rate limit, waiting, was the
     * one it had no way to choose. The status now comes from the error code,
     * in one place, so the two cannot drift apart.
     */
    protected function respond(array $result)
    {
        $status = match ($result['status'] ?? 'error') {
            'success', 'clarification_needed' => 200,
            default => ErrorCode::httpStatus($result['error_code'] ?? null),
        };

        // Tells a client it is worth trying again, without it having to know
        // which codes are transient.
        if (($result['status'] ?? '') === 'error') {
            $result['retryable'] = ErrorCode::isRetryable($result['error_code'] ?? null);
        }

        $response = response()->json($result, $status);

        // Standard back-off signal, so generic HTTP clients and queue workers
        // behave sensibly without reading the body at all.
        if ($status === 429) {
            $response->header('Retry-After', (string) config('naturalquery.retry.retry_after_seconds', 60));
        }

        return $response;
    }

    /**
     * Package info endpoint (replaces dashboard view).
     */
    public function index()
    {
        return response()->json([
            'package' => 'laravel-natural-query',
            'health' => $this->orchestrator->healthCheck(),
            'endpoints' => [
                'POST /text' => 'Natural language query',
                'POST /voice' => 'Voice query',
                'POST /conversation' => 'Multi-turn conversation',
                'GET /health' => 'Health check',
                'GET /schemes' => 'Available schemas',
                'POST /feedback' => 'Submit correction',
            ],
        ]);
    }

    /**
     * Process a text query.
     */
    public function textQuery(Request $request)
    {
        $data = $request->validate([
            'text' => 'required|string|min:3|max:' . config('naturalquery.privacy.max_query_length', 1000),
            'scheme' => 'nullable|string|max:100',
            'disable_tts' => 'boolean',
        ]);

        $requestId = (string) Str::uuid();
        $startTime = microtime(true);

        $result = $this->orchestrator->query(
            $data['text'],
            $data['scheme'] ?? null
        );

        $result['metadata'] = array_merge($result['metadata'] ?? [], [
            'request_id' => $requestId,
            'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'original_query' => $data['text'],
        ]);

        return $this->respond($result);
    }

    /**
     * Process a voice query (audio input).
     */
    public function voiceQuery(Request $request)
    {
        $data = $request->validate([
            // Base64 encoded audio. Bounded: ~10 MB of audio ≈ 14M base64
            // chars — without a cap, an oversized payload ties up memory and
            // gets forwarded to the LLM provider at your expense.
            'audio' => 'required|string|max:14000000',
            'mime_type' => 'nullable|string|max:50',
            'scheme' => 'nullable|string|max:100',
        ]);

        $requestId = (string) Str::uuid();
        $startTime = microtime(true);

        $result = $this->orchestrator->voiceQuery(
            $data['audio'],
            $data['mime_type'] ?? 'audio/webm',
            $data['scheme'] ?? null
        );

        $result['metadata'] = array_merge($result['metadata'] ?? [], [
            'request_id' => $requestId,
            'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
        ]);

        return $this->respond($result);
    }

    /**
     * Health check endpoint.
     */
    public function health()
    {
        $health = $this->orchestrator->healthCheck();

        return response()->json(array_merge($health, [
            'timestamp' => now()->toISOString(),
        ]), $health['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * List available schemes.
     */
    public function schemes(Request $request)
    {
        $schemes = $this->orchestrator->getSchemes();

        // If a specific scheme is requested, include its metrics
        $schemeKey = $request->query('scheme');
        if ($schemeKey) {
            $metrics = $this->orchestrator->getSchemeMetrics($schemeKey);
            return response()->json([
                'scheme' => $schemeKey,
                'metrics' => $metrics,
            ]);
        }

        return response()->json([
            'schemes' => $schemes,
            'total' => count($schemes),
        ]);
    }

    /**
     * Cache statistics.
     */
    public function cacheStats()
    {
        $stats = $this->orchestrator->getCacheStats();

        return response()->json(array_merge($stats, [
            'timestamp' => now()->toISOString(),
        ]));
    }

    /**
     * Clear cache entries.
     */
    public function clearCache(Request $request)
    {
        $data = $request->validate([
            'scheme' => 'nullable|string|max:100',
            'older_than_days' => 'nullable|integer|min:0',
            'min_hits' => 'nullable|integer|min:0',
        ]);

        $deleted = $this->orchestrator->clearCache(
            $data['scheme'] ?? null,
            $data['older_than_days'] ?? 0,
            $data['min_hits'] ?? 0
        );

        return response()->json([
            'status' => 'success',
            'deleted_entries' => $deleted,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Multi-turn conversation query.
     */
    public function conversationQuery(Request $request)
    {
        $data = $request->validate([
            'text' => 'required|string|min:1|max:' . config('naturalquery.privacy.max_query_length', 1000),
            'session_id' => 'required|string|max:100',
            'scheme' => 'nullable|string|max:100',
        ]);

        $result = $this->conversation->query(
            $data['session_id'],
            $data['text'],
            $data['scheme'] ?? null
        );

        return $this->respond($result);
    }

    /**
     * Clear conversation context.
     */
    /**
     * Step back to how the query stood before the last turn.
     *
     * "No, go back to revenue" is a state restore rather than another
     * interpretation — every turn's state is kept, so returning to one is
     * exact instead of being re-derived from the conversation.
     */
    public function rewindConversation(Request $request, string $sessionId)
    {
        $steps = max(1, min(10, (int) $request->input('steps', 1)));

        return response()->json($this->conversation->rewind($sessionId, $steps));
    }

    public function clearConversation(string $sessionId)
    {
        $this->conversation->clearContext($sessionId);
        return response()->json(['status' => 'cleared', 'session_id' => $sessionId]);
    }

    /**
     * Submit feedback (correction or positive signal).
     */
    public function submitFeedback(Request $request)
    {
        $data = $request->validate([
            'query' => 'required|string|max:1000',
            'scheme' => 'required|string|max:100',
            'generated_sql' => 'nullable|string',
            'correction' => 'nullable|string|max:2000',
            'corrected_sql' => 'nullable|string',
            'feedback_type' => 'nullable|string|in:wrong_metric,wrong_table,wrong_result,wrong_filter,positive,other',
            'is_positive' => 'nullable|boolean',
        ]);

        // Security: Sanitize feedback content to prevent prompt injection via stored corrections
        $inputGuard = app(\Jayanta\NaturalQuery\Security\InputGuard::class);

        $correction = $data['correction'] ?? '';
        $correctedSql = $data['corrected_sql'] ?? null;

        // Check correction text for injection attempts
        if ($correction) {
            $guardResult = $inputGuard->validate($correction);
            if (!$guardResult['safe']) {
                return response()->json(['status' => 'rejected', 'reason' => 'Correction text contains disallowed patterns.'], 422);
            }
        }

        // Validate corrected SQL if provided
        if ($correctedSql) {
            $sqlValidator = app(\Jayanta\NaturalQuery\Contracts\SqlValidatorInterface::class);
            $allowedTables = app(\Jayanta\NaturalQuery\Schema\SchemaRegistry::class)->getAllowedTables();
            $validation = $sqlValidator->validate($correctedSql, $allowedTables);
            if (!$validation['valid']) {
                return response()->json(['status' => 'rejected', 'reason' => 'Corrected SQL failed validation: ' . $validation['reason']], 422);
            }
        }

        if ($data['is_positive'] ?? false) {
            $success = $this->feedback->recordPositive(
                $data['query'],
                $data['scheme'],
                $data['generated_sql'] ?? ''
            );
        } else {
            $success = $this->feedback->recordCorrection(
                $data['query'],
                $data['scheme'],
                $data['generated_sql'] ?? '',
                $correction,
                $correctedSql,
                $data['feedback_type'] ?? 'other'
            );
        }

        return response()->json([
            'status' => $success ? 'recorded' : 'failed',
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Feedback statistics.
     */
    public function feedbackStats()
    {
        return response()->json($this->feedback->getStatistics());
    }
}
