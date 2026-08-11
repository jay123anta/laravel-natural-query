<?php

namespace Jayanta\NaturalQuery\Console;

use Illuminate\Console\Command;
use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;

/**
 * Cache Cleanup Command
 *
 * Cleans up stale or low-hit cache entries.
 * Designed to be run via scheduler (e.g., daily).
 */
class CacheCleanupCommand extends Command
{
    protected $signature = 'naturalquery:cache-cleanup
                            {--days=30 : Remove entries not hit in this many days}
                            {--min-hits=2 : Only remove entries with fewer than this many hits}
                            {--dataset= : Only clean entries for a specific dataset}
                            {--all : Remove ALL cache entries (use with caution)}';

    protected $description = 'Clean up stale NaturalQuery cache entries';

    public function handle(QueryCacheInterface $cache): int
    {
        if ($this->option('all')) {
            if (!$this->confirm('This will remove ALL cache entries. Continue?')) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }

            $deleted = $cache->clear();
            $this->info("Cleared all {$deleted} cache entries.");
            return self::SUCCESS;
        }

        $days = (int) $this->option('days');
        $minHits = (int) $this->option('min-hits');
        $dataset = $this->option('dataset');

        $this->info('Cleaning NaturalQuery cache...');
        $this->line("  Older than: {$days} days");
        $this->line("  Min hits: {$minHits}");
        if ($dataset) {
            $this->line("  Dataset: {$dataset}");
        }

        $deleted = $cache->clear($dataset, $days, $minHits);

        $this->info("Cleaned up {$deleted} stale cache entries.");

        // Show remaining stats
        $stats = $cache->getStatistics();
        $this->line("Remaining entries: " . ($stats['total_entries'] ?? 0));
        $this->line("Total hits: " . ($stats['total_hits'] ?? 0));

        return self::SUCCESS;
    }
}
