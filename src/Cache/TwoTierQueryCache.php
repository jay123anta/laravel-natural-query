<?php

namespace Jayanta\NaturalQuery\Cache;

use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;
use Jayanta\NaturalQuery\Contracts\ScopesCacheByDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Two-Tier Query Cache
 *
 * Tier 1 (Redis/Memory): Fast exact-match lookups via Laravel Cache (~1ms)
 * Tier 2 (Database): Persistent storage + fuzzy matching + analytics (~5-20ms)
 *
 * Flow:
 * 1. Check Tier 1 for exact hash match (fastest)
 * 2. If miss, check Tier 2 for exact hash match
 * 3. If miss, try fuzzy match in Tier 2 (Levenshtein + Jaccard)
 * 4. On hit, update hit count
 * 5. Store new entries in both tiers
 */
class TwoTierQueryCache implements QueryCacheInterface, ScopesCacheByDataset
{
    protected array $fillerWords = [
        'show', 'me', 'the', 'please', 'can', 'you', 'what', 'is', 'are',
        'give', 'tell', 'find', 'get', 'display', 'list', 'i', 'want',
        'to', 'see', 'know', 'about', 'for', 'of', 'in', 'a', 'an',
        'how', 'many', 'much', 'which', 'could', 'would', 'like',
    ];

    protected array $synonymMappings = [
        'top' => 'best', 'bottom' => 'worst', 'highest' => 'best',
        'lowest' => 'worst', 'maximum' => 'best', 'minimum' => 'worst',
        'most' => 'best', 'least' => 'worst', 'pending' => 'pending',
        'backlog' => 'pending', 'waiting' => 'pending', 'approved' => 'approved',
        'sanctioned' => 'approved', 'delivered' => 'delivered',
        'completed' => 'completed', 'done' => 'completed',
        'rejected' => 'rejected', 'denied' => 'rejected', 'cancelled' => 'rejected',
    ];

    protected int $cacheTtlSeconds;
    protected float $similarityThreshold;
    protected bool $useTier1;
    protected string $tier1Prefix;
    protected ?string $tier1Store;
    protected string $tableName;

    /**
     * The scope of the lookup in progress, for findFuzzyMatch().
     *
     * Set by findForDataset() immediately before the fuzzy tier runs.
     * Per-call state on a shared object is not free, and it is here only
     * because findFuzzyMatch() is a protected extension point whose
     * signature cannot change without fataling adopter subclasses. The
     * window is synchronous and one method wide.
     */
    protected ?string $askingScopeForLookup = null;

    public function __construct()
    {
        $this->cacheTtlSeconds = (int) config('naturalquery.cache.ttl', 86400);
        $this->similarityThreshold = (float) config('naturalquery.cache.similarity_threshold', 0.85);
        $this->tier1Prefix = config('naturalquery.cache.tier1_prefix', 'naturalquery:');
        $this->tableName = config('naturalquery.cache.table_name', 'naturalquery_cache');

        // Determine Tier 1 cache store
        $configuredStore = config('naturalquery.cache.tier1_store');
        $defaultDriver = config('cache.default');

        if ($configuredStore) {
            $this->useTier1 = true;
            $this->tier1Store = $configuredStore;
        } else {
            $this->useTier1 = in_array($defaultDriver, ['redis', 'memcached', 'array', 'file', 'database']);
            $this->tier1Store = $defaultDriver;
        }
    }

    /**
     * Find a cached result for a query.
     *
     * $datasetHint (NQ-003) is the dataset the ASKING question resolves to,
     * not a filter on which row's text may match — Tier 1 and Tier 2 exact
     * stay purely hash-based, unchanged, because an identical normalized
     * question under the current contract version is already a strong
     * enough signal that this is the same question asked before. It is
     * consulted only to decide whether the FUZZY tier may run at all: fuzzy
     * matches by TEXT alone, so a row it returns can belong to any dataset,
     * and the caller (QueryOrchestrator) is the one that can tell whether a
     * hit answers the dataset actually asked about, and re-target or refuse
     * it accordingly. Without any way to name the asking dataset there is
     * nothing to check that against, so fuzzy matching is skipped rather
     * than guessed at — a miss costs one API call, a wrong hit costs a
     * wrong answer (AGENTS.md §0).
     */
    public function find(string $query): ?array
    {
        return $this->findForDataset($query, null);
    }

    public function findForDataset(string $query, ?string $datasetHint = null): ?array
    {
        // A cache that cannot be read is a cache miss, not a failed question.
        //
        // cache.enabled defaults to true, so an install that has not run
        // `php artisan migrate` yet queries a table that does not exist. The
        // QueryException travelled up to QueryOrchestrator's catch and every
        // single question came back "An error occurred processing your query."
        // — no mention of a cache, a table, or a migration. That is the first
        // thing a new adopter sees, on the stacks Rule 0 names by name.
        //
        // Degrading to a miss costs one API call and keeps the package working;
        // the log says exactly which command fixes it.
        try {
            return $this->lookup($query, $datasetHint);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning(
                '[NaturalQuery:Cache] Cache table unreadable, continuing without it. '
                . "Run `php artisan migrate` to create `{$this->tableName}`.",
                ['error' => $e->getMessage()]
            );

            return null;
        }
    }

    private function lookup(string $query, ?string $datasetHint): ?array
    {
        $normalized = $this->normalizeQuery($query);
        $hash = $this->generateHash($this->scopedKey($normalized, $datasetHint));

        // TIER 1: Fast cache lookup
        if ($this->useTier1) {
            $tier1Result = $this->findInTier1($hash);
            if ($tier1Result) {
                Log::debug('[NaturalQuery:Cache] Tier 1 hit', ['hash' => substr($hash, 0, 16)]);
                $this->incrementHitCountByHash($hash);
                return $tier1Result;
            }
        }

        // TIER 2: Database exact match
        $cached = $this->findExactMatch($hash);
        if ($cached) {
            $this->incrementHitCount($cached->id);
            $result = $this->formatCacheResult($cached, 'exact');
            if ($this->useTier1) {
                $this->storeInTier1($hash, $result);
            }
            Log::debug('[NaturalQuery:Cache] Tier 2 exact hit');
            return $result;
        }

        // TIER 2: Fuzzy match, within the same asking scope.
        //
        // It used to return null here whenever the scope was unresolved,
        // because fuzzy matches by TEXT and a row it returned could belong to
        // any dataset. The per-candidate scope filter inside findFuzzyMatch()
        // now handles that directly, INCLUDING the null case: an unscoped
        // question matches only rows that were themselves cached unscoped.
        //
        // Keeping the early return on top of that filter cost every fuzzy hit
        // for every unscoped question — the general chat box on a
        // multi-dataset install, which is the shape most adopters ship — while
        // protecting against nothing the filter does not already cover.
        $this->askingScopeForLookup = $datasetHint;
        $fuzzyMatch = $this->findFuzzyMatch($normalized);
        if ($fuzzyMatch) {
            $this->incrementHitCount($fuzzyMatch->id);
            Log::debug('[NaturalQuery:Cache] Tier 2 fuzzy hit', ['similarity' => $fuzzyMatch->similarity ?? 'N/A']);
            return $this->formatCacheResult($fuzzyMatch, 'fuzzy');
        }

        Log::debug('[NaturalQuery:Cache] Cache miss', ['query' => $query]);
        return null;
    }

    /**
     * Store a query result in cache.
     */
    public function store(string $query, array $intent): bool
    {
        try {
            $normalized = $this->normalizeQuery($query);
            // The scope this question was asked under, put there by
            // QueryOrchestrator::rememberIntent(). Part of the row's identity,
            // so a second scope adds a row instead of replacing the first.
            $hash = $this->generateHash($this->scopedKey($normalized, $intent['_asking_scope'] ?? null));

            $existing = DB::table($this->tableName)
                ->where('query_hash', $hash)
                ->first();

            if ($existing) {
                // Every derived column, not just the blob. They are projections
                // OF the intent, and rewriting the intent while leaving them
                // behind makes them describe an answer that is no longer
                // stored. `dataset` is the one that matters: it is what
                // naturalquery:cache-cleanup --dataset and the stats command
                // read, so once the same wording was asked about a second
                // dataset the documented remedy for a stale answer quietly
                // deleted nothing.
                DB::table($this->tableName)
                    ->where('id', $existing->id)
                    ->update([
                        'contract_version' => static::INTENT_CONTRACT_VERSION,
                        'intent' => json_encode($intent),
                        'dataset' => $intent['dataset'] ?? null,
                        'metric' => $intent['metric'] ?? null,
                        'group_value' => $intent['group_value'] ?? null,
                        'limit_value' => $intent['limit'] ?? null,
                        'order_direction' => $intent['order'] ?? null,
                        'query_type' => $intent['query_type'] ?? null,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table($this->tableName)->insert([
                    'query_hash' => $hash,
                    'contract_version' => static::INTENT_CONTRACT_VERSION,
                    'original_query' => $query,
                    'normalized_query' => $normalized,
                    'dataset' => $intent['dataset'] ?? null,
                    'metric' => $intent['metric'] ?? null,
                    'group_value' => $intent['group_value'] ?? null,
                    'intent' => json_encode($intent),
                    'limit_value' => $intent['limit'] ?? null,
                    'order_direction' => $intent['order'] ?? null,
                    'query_type' => $intent['query_type'] ?? null,
                    'hit_count' => 1,
                    'last_hit_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Store in Tier 1
            if ($this->useTier1) {
                $cacheData = [
                    'cached' => true,
                    'cache_match_type' => 'exact',
                    'intent' => $intent,
                    'dataset' => $intent['dataset'] ?? null,
                    'metric' => $intent['metric'] ?? null,
                    'group_value' => $intent['group_value'] ?? null,
                    'limit' => $intent['limit'] ?? null,
                    'order' => $intent['order'] ?? null,
                    'query_type' => $intent['query_type'] ?? null,
                ];
                $this->storeInTier1($hash, $cacheData);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('[NaturalQuery:Cache] Store failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get cache statistics.
     */
    public function getStatistics(): array
    {
        try {
            $stats = DB::table($this->tableName)
                ->selectRaw('
                    COUNT(*) as total_entries,
                    COALESCE(SUM(hit_count), 0) as total_hits,
                    COALESCE(AVG(hit_count), 0) as avg_hits_per_entry,
                    COALESCE(MAX(hit_count), 0) as max_hits,
                    COUNT(DISTINCT dataset) as unique_datasets,
                    MIN(created_at) as oldest_entry,
                    MAX(last_hit_at) as most_recent_hit
                ')
                ->first();

            $topDatasets = DB::table($this->tableName)
                ->selectRaw('dataset, COUNT(*) as entries, SUM(hit_count) as total_hits')
                ->whereNotNull('dataset')
                ->groupBy('dataset')
                ->orderByDesc('total_hits')
                ->limit(10)
                ->get();

            $topQueries = DB::table($this->tableName)
                ->select('original_query', 'dataset', 'hit_count', 'last_hit_at')
                ->orderByDesc('hit_count')
                ->limit(10)
                ->get();

            return [
                'enabled' => true,
                'total_entries' => (int) ($stats->total_entries ?? 0),
                'total_hits' => (int) ($stats->total_hits ?? 0),
                'avg_hits_per_entry' => round($stats->avg_hits_per_entry ?? 0, 2),
                'max_hits' => (int) ($stats->max_hits ?? 0),
                'unique_datasets' => (int) ($stats->unique_datasets ?? 0),
                'oldest_entry' => $stats->oldest_entry,
                'most_recent_hit' => $stats->most_recent_hit,
                'tier1_enabled' => $this->useTier1,
                'tier1_store' => $this->tier1Store,
                'top_datasets' => $topDatasets,
                'top_queries' => $topQueries,
            ];
        } catch (\Exception $e) {
            Log::error('[NaturalQuery:Cache] Statistics failed', ['error' => $e->getMessage()]);
            return ['enabled' => true, 'total_entries' => 0, 'total_hits' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clear cache entries.
     */
    public function clear(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        try {
            $query = DB::table($this->tableName);

            if ($dataset) {
                $query->where('dataset', $dataset);
            }

            if ($olderThanDays > 0) {
                $query->where('last_hit_at', '<', now()->subDays($olderThanDays));
            }

            if ($minHits > 0) {
                $query->where('hit_count', '<', $minHits);
            }

            // Tier 1 first, and for EVERY filter, not just --dataset.
            //
            // Tier 1 is checked before Tier 2, so a row deleted from the
            // database keeps answering from memory for up to cache.ttl —
            // 24 hours by default. The forget loop used to live inside
            // `if ($dataset)`, so `--all`, a bare run, `--days` and
            // `--min-hits` all deleted the durable copy and left the fast one
            // serving: the operator saw "Cleared all N cache entries" and the
            // stale answers carried on. docs/CACHING.md says --all empties the
            // cache, and it has to mean both tiers.
            //
            // Collected before the delete, because afterwards there is nothing
            // left to read the hashes from.
            if ($this->useTier1) {
                foreach ((clone $query)->pluck('query_hash') as $hash) {
                    $this->getCacheStore()->forget($this->tier1Prefix . $hash);
                }
            }

            $deleted = $query->delete();

            Log::info('[NaturalQuery:Cache] Cleared entries', [
                'deleted' => $deleted,
                'dataset' => $dataset,
                'older_than_days' => $olderThanDays,
            ]);

            return $deleted;
        } catch (\Exception $e) {
            Log::error('[NaturalQuery:Cache] Clear failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    // =========================================================================
    // Internal Methods
    // =========================================================================

    /**
     * Normalize query for consistent caching.
     */
    public function normalizeQuery(string $query): string
    {
        $query = strtolower(trim($query));
        $query = preg_replace('/[^\w\s\-]/', '', $query);
        $words = preg_split('/\s+/', $query);

        // Filler words go; single characters stay.
        //
        // Dropping tokens of length 1 deleted the only thing distinguishing
        // some questions from each other. "revenue for region a" and "revenue
        // for region b" normalised identically, hashed identically, and shared
        // one row — so the second returned the first's number, with the right
        // shape and nothing in the answer text to reveal it. The scope guard
        // cannot help: both questions resolve to the same scope.
        //
        // Single-character values are ordinary in real schemas — grade A,
        // block B, class C, zone 1 — and a token that changes the answer
        // belongs in the key.
        $words = array_filter($words, function ($word) {
            return $word !== '' && !in_array($word, $this->fillerWords);
        });

        $words = array_map(function ($word) {
            return $this->synonymMappings[$word] ?? $word;
        }, $words);

        $words = array_unique($words);
        sort($words);

        return implode(' ', $words);
    }

    /**
     * Version of the parsed-intent contract these entries were built against.
     *
     * Bump whenever a field is added to or reinterpreted in the intent, so an
     * upgrade misses the old rows instead of serving them. Intents cached
     * before `group_by` existed answer "revenue by region" with the default
     * grouping; without this they would keep doing so until the TTL expired,
     * and the upgrade would look like it had changed nothing.
     *
     * 3 — 2.0.0 renamed the `scheme` slot to `dataset`. The stored intent is a
     * JSON blob, so the column rename does not touch what is inside it: a row
     * written by 1.0.0 still says "scheme". Served to 2.0.0 the dataset reads
     * null, and Tier 2 has no expiry, so every question asked before the
     * upgrade would have stayed broken until someone ran cache-cleanup.
     *
     * 4 — 2.1.0 added `_asking_scope`: the dataset the ASKING question was
     * scoped to, which is not the same thing as the dataset the answer turned
     * out to be about. A row written before this carries no scope at all, and
     * reading a missing scope as "unscoped" would make every pre-upgrade row
     * eligible for unscoped questions — which is exactly the cross-dataset
     * hit this release exists to close. Missing it must mean unknown, and
     * unknown must miss.
     */
    protected const INTENT_CONTRACT_VERSION = 4;

    /**
     * The words a row is keyed by, with the asking scope folded in.
     *
     * The scope has to reach the key because `query_hash` is UNIQUE and
     * store() updates the row it finds. Keyed on the text alone there is
     * exactly one row per wording, so two dataset-scoped pages asking the same
     * words took turns overwriting each other — miss, regenerate, overwrite,
     * miss — and neither saw a hit again. Refusing to SHARE a row across
     * scopes is the point; refusing to STORE both was an accident of the key.
     *
     * It goes in HERE, in the string, rather than as a parameter on
     * generateHash(). generateHash() is `protected` on a class docs/CACHING.md
     * tells adopters to subclass, so adding even an optional parameter to it
     * is a fatal error at class load for anyone who overrode it — the same
     * break this release already made once on QueryCacheInterface::find(),
     * recorded as an anti-pattern, and then made twice more here. A subclass
     * that overrides generateHash() still receives a string and still works.
     */
    protected function scopedKey(string $normalizedQuery, ?string $askingScope): string
    {
        return ($askingScope ?? '') . '|' . $normalizedQuery;
    }

    /**
     * The row's identity: contract version and the (scoped) words.
     *
     * SIGNATURE FROZEN. Overridden by adopter subclasses; see scopedKey().
     */
    protected function generateHash(string $normalizedQuery): string
    {
        return hash('sha256', static::INTENT_CONTRACT_VERSION . '|' . $normalizedQuery);
    }

    protected function getCacheStore()
    {
        return $this->tier1Store ? Cache::store($this->tier1Store) : Cache::store();
    }

    protected function findInTier1(string $hash): ?array
    {
        try {
            $cached = $this->getCacheStore()->get($this->tier1Prefix . $hash);
            if ($cached && is_array($cached)) {
                $cached['cache_match_type'] = 'tier1';
                return $cached;
            }
        } catch (\Exception $e) {
            Log::warning('[NaturalQuery:Cache] Tier 1 lookup failed', ['error' => $e->getMessage()]);
        }
        return null;
    }

    protected function storeInTier1(string $hash, array $data): void
    {
        try {
            $this->getCacheStore()->put($this->tier1Prefix . $hash, $data, $this->cacheTtlSeconds);
        } catch (\Exception $e) {
            Log::warning('[NaturalQuery:Cache] Tier 1 store failed', ['error' => $e->getMessage()]);
        }
    }

    protected function findExactMatch(string $hash): ?object
    {
        return DB::table($this->tableName)
            ->where('query_hash', $hash)
            ->where('contract_version', static::INTENT_CONTRACT_VERSION)
            ->first();
    }

    /**
     * The version filter is not redundant with the hash.
     *
     * Fuzzy matching finds rows by their normalized TEXT, so it never touches
     * the hash and the contract version folded into it had no effect here at
     * all. Bumping the constant made the exact tier miss an old row and the
     * fuzzy tier serve the very same row a moment later — which quietly
     * defeated every previous bump too, not just this one.
     */
    /**
     * The nearest row by wording, within the same asking scope.
     *
     * The exact tiers get their scope from the hash. This one matches by TEXT,
     * so without the same filter it returns candidates from other scopes that
     * the engine then discards — and it has already counted them as hits by
     * then, so naturalquery:cache-stats reported reuse for rows that were
     * never once served.
     */
    protected function findFuzzyMatch(string $normalizedQuery): ?object
    {
        // Read from the property rather than taken as a parameter, for the
        // same reason as generateHash(): this method is protected on a class
        // adopters are told to subclass, and widening it fatals anyone who
        // overrode the old signature at class load.
        $askingScope = $this->askingScopeForLookup;

        $words = explode(' ', $normalizedQuery);
        $significantWords = array_filter($words, fn($w) => strlen($w) > 3);

        if (empty($significantWords)) {
            return null;
        }

        $candidates = DB::table($this->tableName)
            ->where('contract_version', static::INTENT_CONTRACT_VERSION)
            ->where(function ($query) use ($significantWords) {
                foreach ($significantWords as $word) {
                    $query->orWhere('normalized_query', 'LIKE', "%{$word}%");
                }
            })
            // The scope filter below runs in PHP, because the scope lives
            // inside the intent JSON and cannot be indexed portably. So the
            // shortlist has to be wide enough that the right-scope candidates
            // are not crowded out of it: ordered by hit_count, 50 rows on a
            // busy multi-dataset install can be entirely other scopes, and the
            // fuzzy tier would silently stop working for the newer one.
            ->orderBy('hit_count', 'desc')
            ->limit(200)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($candidates as $candidate) {
            $stored = json_decode($candidate->intent ?? '', true);
            if (($stored['_asking_scope'] ?? null) !== $askingScope) {
                continue;
            }

            $similarity = $this->calculateSimilarity($normalizedQuery, $candidate->normalized_query);
            if ($similarity >= $this->similarityThreshold && $similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = $candidate;
                $bestMatch->similarity = $similarity;
            }
        }

        return $bestMatch;
    }

    protected function calculateSimilarity(string $query1, string $query2): float
    {
        // Jaccard similarity (word overlap)
        $words1 = explode(' ', $query1);
        $words2 = explode(' ', $query2);
        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));
        $jaccard = $union > 0 ? $intersection / $union : 0;

        // Levenshtein similarity (character-level)
        $maxLen = max(strlen($query1), strlen($query2));
        $lev = levenshtein($query1, $query2);
        $levSimilarity = $maxLen > 0 ? 1 - ($lev / $maxLen) : 1;

        // Weighted: favor word overlap for semantic matching
        return (0.6 * $jaccard) + (0.4 * $levSimilarity);
    }

    protected function incrementHitCount(int $id): void
    {
        try {
            DB::table($this->tableName)
                ->where('id', $id)
                ->update(['hit_count' => DB::raw('hit_count + 1'), 'last_hit_at' => now()]);
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    protected function incrementHitCountByHash(string $hash): void
    {
        try {
            DB::table($this->tableName)
                ->where('query_hash', $hash)
                ->update(['hit_count' => DB::raw('hit_count + 1'), 'last_hit_at' => now()]);
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    protected function formatCacheResult(object $cached, string $matchType): array
    {
        $intent = json_decode($cached->intent, true);

        return [
            'cached' => true,
            'cache_match_type' => $matchType,
            'cache_id' => $cached->id,
            'hit_count' => $cached->hit_count,
            'original_cached_query' => $cached->original_query,
            'intent' => $intent,
            'dataset' => $intent['dataset'] ?? $cached->dataset,
            'metric' => $intent['metric'] ?? $cached->metric,
            'group_value' => $intent['group_value'] ?? $cached->group_value,
            'limit' => $intent['limit'] ?? $cached->limit_value,
            'order' => $intent['order'] ?? $cached->order_direction,
            'query_type' => $intent['query_type'] ?? $cached->query_type,
        ];
    }
}
