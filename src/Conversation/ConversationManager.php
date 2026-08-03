<?php

namespace Jayanta\NaturalQuery\Conversation;

use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Conversation Manager
 *
 * Enables multi-turn conversations where follow-up queries
 * inherit context from previous queries.
 *
 * Example, using whatever datasets the application actually registers:
 *   Turn 1: "top customers by revenue in orders"  → scheme=orders, metric=revenue
 *   Turn 2: "now filter by North"                 → keeps scheme and metric, adds the filter
 *   Turn 3: "compare with South"                  → keeps scheme and metric, compares the two
 *   Turn 4: "what about inventory"                → switches dataset, keeps metric where it applies
 *
 * Nothing here knows any domain vocabulary: dataset names and aliases come
 * from the schema registry, so this works the same on any application.
 *
 * Context is stored per-session in cache with a configurable TTL.
 */
class ConversationManager
{
    protected QueryOrchestrator $orchestrator;
    protected SchemaRegistry $registry;
    protected int $contextTtl;
    protected string $cachePrefix = 'nq_conv:';

    public function __construct(QueryOrchestrator $orchestrator, SchemaRegistry $registry)
    {
        $this->orchestrator = $orchestrator;
        $this->registry = $registry;
        $this->contextTtl = config('naturalquery.conversation.ttl', 1800); // 30 minutes
    }

    /**
     * Process a query within a conversation context.
     *
     * @param string $sessionId Unique session identifier (e.g., user ID, session token)
     * @param string $query The user's natural language query
     * @param string|null $schemeHint Optional explicit scheme
     * @return array Response with conversation metadata
     */
    public function query(string $sessionId, string $query, ?string $schemeHint = null): array
    {
        // Load existing context
        $context = $this->getContext($sessionId);

        // Detect if this is a follow-up or a new topic
        $enrichedQuery = $this->enrichWithContext($query, $context);
        $effectiveSchemeHint = $schemeHint ?? $context['scheme'] ?? null;

        // Run the query through the orchestrator
        $result = $this->orchestrator->query($enrichedQuery, $effectiveSchemeHint);

        // Update context from the result
        if (($result['status'] ?? '') === 'success') {
            $this->updateContext($sessionId, $result, $query);
        }

        // Add conversation metadata
        $result['conversation'] = [
            'session_id' => $sessionId,
            'turn' => ($context['turn'] ?? 0) + 1,
            'context_active' => !empty($context),
            'context_scheme' => $context['scheme'] ?? null,
            'enriched_query' => $enrichedQuery !== $query ? $enrichedQuery : null,
        ];

        return $result;
    }

    /**
     * Enrich a follow-up query with context from previous turns.
     *
     * Detects follow-up patterns and injects missing context.
     */
    protected function enrichWithContext(string $query, array $context): string
    {
        if (empty($context)) {
            return $query;
        }

        $queryLower = strtolower(trim($query));

        // Pattern: "now filter by X" / "filter by X" / "for X"
        if (preg_match('/^(?:now\s+)?(?:filter|show|only)\s+(?:by|for)\s+(.+)/i', $queryLower, $m)) {
            $filter = trim($m[1]);
            $scheme = $context['scheme'] ?? '';
            $metric = $context['metric'] ?? '';
            return "{$metric} in {$scheme} for {$filter}";
        }

        // Pattern: "compare with X" / "vs X" / "and X"
        if (preg_match('/^(?:compare\s+with|vs|versus|and)\s+(.+)/i', $queryLower, $m)) {
            $other = trim($m[1]);
            $groupValue = $context['group_value'] ?? '';
            $scheme = $context['scheme_name'] ?? $context['scheme'] ?? '';
            $metric = $context['metric'] ?? '';
            if ($groupValue) {
                return "compare {$groupValue} and {$other} in {$scheme} for {$metric}";
            }
            return "show {$other} in {$scheme} for {$metric}";
        }

        // Pattern: "what about X scheme" / "switch to X"
        if (preg_match('/^(?:what\s+about|switch\s+to|change\s+to|show\s+me)\s+(.+?)(?:\s+scheme)?$/i', $queryLower, $m)) {
            $newTopic = trim($m[1]);
            $metric = $context['metric'] ?? '';
            $groupValue = $context['group_value'] ?? '';
            if ($groupValue) {
                return "{$metric} for {$groupValue} in {$newTopic}";
            }
            if ($metric) {
                return "{$metric} in {$newTopic}";
            }
            return $newTopic;
        }

        // Pattern: "top/bottom N" without scheme (inherits scheme)
        if (preg_match('/^(?:top|bottom|best|worst)\s+\d+/i', $queryLower)) {
            $scheme = $context['scheme_name'] ?? $context['scheme'] ?? '';
            if ($scheme && stripos($queryLower, $scheme) === false) {
                return "{$query} in {$scheme}";
            }
        }

        // Pattern: single word that looks like a district name (follow-up detail)
        if (!str_contains($queryLower, ' ') || preg_match('/^[a-z\s]{3,30}$/i', $queryLower)) {
            $scheme = $context['scheme_name'] ?? $context['scheme'] ?? '';
            $metric = $context['metric'] ?? '';
            if ($scheme && !$this->looksLikeScheme($queryLower)) {
                return "show {$query} details in {$scheme}";
            }
        }

        return $query;
    }

    /**
     * Does this text name one of the datasets registered in THIS application?
     *
     * Asks the registry rather than matching a fixed word list. The previous
     * implementation hardcoded the scheme names of the project this package
     * was extracted from, which meant the check was meaningless anywhere else:
     * an app with "orders" and "inventory" datasets matched nothing, so every
     * short follow-up was treated as a record lookup, while an unrelated app
     * that happened to mention "housing" was told it had switched dataset.
     *
     * Domain vocabulary belongs in the schema config files, never in here.
     */
    protected function looksLikeScheme(string $text): bool
    {
        $text = strtolower(trim($text));

        if ($text === '') {
            return false;
        }

        foreach ($this->registry->getAvailableSchemes() as $scheme) {
            $candidates = array_merge(
                [$scheme['key'] ?? '', $scheme['name'] ?? ''],
                (array) ($scheme['aliases'] ?? [])
            );

            foreach ($candidates as $candidate) {
                $candidate = strtolower(trim((string) $candidate));

                // Require a real word, not an incidental substring: "order"
                // must not match inside "reorder point".
                if ($candidate !== '' && preg_match('/\b' . preg_quote($candidate, '/') . '\b/', $text)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get conversation context from cache.
     * Session is scoped to authenticated user to prevent session hijacking.
     */
    protected function getContext(string $sessionId): array
    {
        return Cache::get($this->cachePrefix . $this->scopeSession($sessionId), []);
    }

    /**
     * Scope session_id to the authenticated user.
     * Prevents one user from accessing another user's conversation.
     */
    protected function scopeSession(string $sessionId): string
    {
        $userId = auth()->id() ?? 'anon';
        return $userId . ':' . $sessionId;
    }

    /**
     * Update conversation context after a successful query.
     */
    protected function updateContext(string $sessionId, array $result, string $originalQuery): void
    {
        $parsed = $result['parsed_query'] ?? [];

        $context = [
            'scheme' => $parsed['scheme'] ?? null,
            'scheme_name' => $result['metadata']['scheme_name'] ?? $parsed['scheme'] ?? null,
            'metric' => $parsed['metric'] ?? null,
            'group_value' => $parsed['group_value'] ?? null,
            'order' => $parsed['order'] ?? null,
            'limit' => $parsed['limit'] ?? null,
            'query_type' => $parsed['query_type'] ?? null,
            'last_query' => $originalQuery,
            'turn' => (Cache::get($this->cachePrefix . $this->scopeSession($sessionId), [])['turn'] ?? 0) + 1,
            'updated_at' => now()->toISOString(),
        ];

        Cache::put($this->cachePrefix . $this->scopeSession($sessionId), $context, $this->contextTtl);
    }

    /**
     * Clear conversation context (start fresh).
     */
    public function clearContext(string $sessionId): void
    {
        Cache::forget($this->cachePrefix . $this->scopeSession($sessionId));
    }

    /**
     * Get current context (for debugging / UI display).
     */
    public function peekContext(string $sessionId): array
    {
        return Cache::get($this->cachePrefix . $this->scopeSession($sessionId), []);
    }
}
