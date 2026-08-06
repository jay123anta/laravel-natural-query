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
     * Hand a follow-up to the model WITH the question it follows.
     *
     * This used to rewrite the question from templates: "only in West" became
     * "show only in West details in Orders", which threw away the metric, so a
     * chain that started with revenue answered the second turn in record counts
     * and the third in nonsense. Every template lost something, because a
     * template can only carry the parts somebody thought to put in it.
     *
     * Sending both questions instead lets the model resolve the reference the
     * way a person would — it already knows the schema, and "only in West" is
     * only ambiguous without the sentence before it.
     */
    protected function enrichWithContext(string $query, array $context): string
    {
        if (empty($context['last_query']) || !$this->looksLikeFollowUp($query)) {
            return $query;
        }

        return sprintf(
            'Earlier question: "%s". Follow-up that refines it, using the same measure '
            . 'and breakdown unless it says otherwise: "%s"',
            $context['last_query'],
            $query
        );
    }

    /**
     * Say what the last turn actually asked, in one self-contained sentence.
     *
     * Built from the resolved intent rather than the words typed, so a chain
     * carries its state forward instead of decaying into fragments. Returns
     * null when there is not enough to restate, and the raw question is kept.
     */
    protected function restate(array $parsed, array $result): ?string
    {
        $metric = $parsed['metric'] ?? null;

        if (!$metric) {
            return null;
        }

        $sentence = $metric;

        if (!empty($parsed['group_by'])) {
            $sentence .= ' by ' . $parsed['group_by'];
        }

        if (!empty($parsed['group_value'])) {
            $sentence .= !empty($parsed['filter_column'])
                ? ' where ' . $parsed['filter_column'] . ' is ' . $parsed['group_value']
                : ' for ' . $parsed['group_value'];
        }

        if (!empty($result['metadata']['time_filter'])) {
            $sentence .= ' ' . $result['metadata']['time_filter'];
        }

        return $sentence;
    }

    /**
     * Is this a refinement of the last question rather than a new one?
     *
     * Deliberately generous: enriching a genuinely new question costs a little
     * prompt and the model ignores the earlier one, while missing a follow-up
     * loses the metric and answers something else entirely.
     */
    protected function looksLikeFollowUp(string $query): bool
    {
        $q = strtolower(trim($query));

        if (preg_match('/^(?:only|just|now|and|but|what about|how about|instead|also|then|filter|show only|restrict|narrow|compare|vs|versus|excluding|without|for|in)\b/', $q)) {
            return true;
        }

        // A very short phrase after a real question is almost always a
        // refinement — "West", "last month", "top 3".
        return str_word_count($q) <= 4;
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
            // What the last turn RESOLVED to, not what was typed.
            //
            // Storing the raw text meant a chain decayed: turn 3's "earlier
            // question" was turn 2's "only in West", a fragment that had
            // already lost the metric from turn 1. Each turn now records a
            // question that stands on its own, so the third follow-up inherits
            // as much as the second did.
            'last_query' => $this->restate($parsed, $result) ?: $originalQuery,
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
