<?php

namespace Jayanta\NaturalQuery\Console;

use Illuminate\Console\Command;
use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;

/**
 * What the query cache is actually doing.
 *
 * `getStatistics()` has existed since 1.0.0 and nothing surfaced it, so the
 * only way to know whether caching was earning its keep was to read the table
 * by hand. That matters more since 2.1.0: fuzzy matching is off by default and
 * a row is only reused within the dataset scope it was cached under, so hit
 * rates are lower on purpose -  and an operator with no numbers cannot tell
 * "correctly conservative" from "silently broken".
 *
 * Read-only. Sends nothing anywhere.
 */
class CacheStatsCommand extends Command
{
    protected $signature = 'naturalquery:cache-stats
                            {--json : Machine-readable output}';

    protected $description = 'Show query cache usage -  entries, reuse, and what is being asked most';

    public function handle(QueryCacheInterface $cache): int
    {
        if (!config('naturalquery.cache.enabled', true)) {
            $this->warn('The query cache is disabled (naturalquery.cache.enabled is false).');

            return self::SUCCESS;
        }

        $stats = $cache->getStatistics();

        if ($this->option('json')) {
            $this->line((string) json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (isset($stats['error'])) {
            $this->error('Could not read cache statistics: ' . $stats['error']);
            $this->line('  If the table is missing, run `php artisan migrate`.');

            return self::FAILURE;
        }

        $entries = (int) ($stats['total_entries'] ?? 0);
        $hits = (int) ($stats['total_hits'] ?? 0);

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>NaturalQuery cache</>');
        $this->newLine();

        if ($entries === 0) {
            $this->line('  Nothing cached yet. Ask a question twice and the second one should be free.');
            $this->newLine();

            return self::SUCCESS;
        }

        // Every row is written on a MISS and its hit_count starts at 1, so
        // (hits - entries) is the number of times a stored answer was actually
        // reused, and entries is the number of questions that had to be paid
        // for. Stating it that way rather than as a bare percentage, because a
        // "hit rate" invites comparison against caches that count differently.
        $reuses = max(0, $hits - $entries);
        $asks = $entries + $reuses;

        $this->line(sprintf('  Distinct questions cached   %d', $entries));
        $this->line(sprintf('  Answers reused              %d', $reuses));
        $this->line(sprintf('  Provider calls avoided      %d of %d asks (%s)', $reuses, $asks,
            $asks > 0 ? round($reuses / $asks * 100) . '%' : '0%'));
        $this->line(sprintf('  Most-reused single question %d times', (int) ($stats['max_hits'] ?? 0)));

        $this->newLine();
        $this->line(sprintf('  Datasets covered            %d', (int) ($stats['unique_datasets'] ?? 0)));
        $this->line(sprintf('  Oldest entry                %s', $stats['oldest_entry'] ?? '- '));
        $this->line(sprintf('  Last reuse                  %s', $stats['most_recent_hit'] ?? 'never'));
        $this->line(sprintf('  Fast tier (Tier 1)          %s', ($stats['tier1_enabled'] ?? false)
            ? 'on via ' . ($stats['tier1_store'] ?? 'default')
            : 'off'));
        $this->line(sprintf('  Fuzzy matching              %s', config('naturalquery.cache.fuzzy_matching', false)
            ? 'on -  see docs/CACHING.md for the trade'
            : 'off (default)'));

        $top = $stats['top_queries'] ?? [];

        if (!empty($top)) {
            $this->newLine();
            $this->line('  <options=bold>Most reused</>');

            foreach ($top as $row) {
                $q = (string) ($row->original_query ?? $row['original_query'] ?? '');
                $n = (int) ($row->hit_count ?? $row['hit_count'] ?? 0);
                $this->line(sprintf('    %4dx  %s', $n, mb_strimwidth($q, 0, 62, '…')));
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>Prune with `php artisan naturalquery:cache-cleanup`.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
