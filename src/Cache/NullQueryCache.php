<?php

namespace Jayanta\NaturalQuery\Cache;

use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;

/**
 * Null Query Cache - used when caching is disabled.
 * All operations are no-ops.
 */
class NullQueryCache implements QueryCacheInterface
{
    public function find(string $query): ?array
    {
        return null;
    }

    public function store(string $query, array $intent): bool
    {
        return true;
    }

    public function getStatistics(): array
    {
        return ['enabled' => false, 'total_entries' => 0, 'total_hits' => 0];
    }

    public function clear(?string $scheme = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        return 0;
    }
}
