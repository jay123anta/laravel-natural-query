<?php

namespace Jayanta\NaturalQuery\Contracts;

/**
 * Query Cache Interface
 *
 * Two-tier cache for storing parsed intents and avoiding repeated AI calls.
 * Tier 1: Fast cache (Redis/memory) for exact matches
 * Tier 2: Database with fuzzy matching for similar queries
 */
interface QueryCacheInterface
{
    /**
     * Find a cached result for a query.
     *
     * @param string $query The natural language query
     * @return array|null Cached intent data, or null if not found
     */
    public function find(string $query): ?array;

    /**
     * Store a query result in cache.
     *
     * @param string $query The original natural language query
     * @param array $intent The parsed intent/result to cache
     * @return bool Whether storing succeeded
     */
    public function store(string $query, array $intent): bool;

    /**
     * Get cache statistics.
     *
     * @return array {total_entries, total_hits, schemes, top_queries}
     */
    public function getStatistics(): array;

    /**
     * Clear cache entries.
     *
     * @param string|null $scheme Clear only entries for this scheme (null = all)
     * @param int $olderThanDays Clear entries older than N days (0 = all ages)
     * @param int $minHits Only clear entries with fewer than N hits (0 = all)
     * @return int Number of entries cleared
     */
    public function clear(?string $scheme = null, int $olderThanDays = 0, int $minHits = 0): int;
}
