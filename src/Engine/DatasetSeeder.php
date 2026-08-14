<?php

namespace Jayanta\NaturalQuery\Engine;

use Illuminate\Support\Facades\Log;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;

/**
 * Dataset Seeder
 *
 * Owns every signal that reads the QUESTION's text to guess which dataset(s)
 * it is about. Nothing downstream of this class inspects the question again
 * — `SchemaShortlister::resolve()` takes seed KEYS only, never the query —
 * which is what makes I10 (no domain knowledge in package code) checkable at
 * a single seam: every signal this class is allowed to use is named in the
 * design's permitted list (routing, aliases, schema keys, default_dataset),
 * and nothing else.
 *
 * Two methods, two callers:
 *
 * `detect()` is `QueryOrchestrator::detectDatasetFromKeywords()` moved here
 * VERBATIM — a single best guess, unchanged, so both existing call sites
 * (initial dataset identification, the refined-prompt retry) stay
 * byte-identical.
 *
 * `seeds()` is new: EVERY matching signal, not just the first. A question
 * mentioning two routing keywords needs BOTH datasets in scope — collapsing
 * to one, the way `detect()` is documented to, would silently narrow a
 * two-table question to the table named first.
 */
class DatasetSeeder
{
    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Detect dataset from query keywords — single best guess.
     *
     * Priority:
     * 1. User-defined query_routing rules (most specific, highest priority)
     * 2. Schema aliases (from schema config files)
     * 3. Column aliases (last resort)
     */
    public function detect(string $query): ?string
    {
        $queryLower = strtolower($query);

        // Priority 1: User-defined routing rules (longest match first)
        $routing = config('naturalquery.query_routing', []);
        if (!empty($routing)) {
            // Sort by key length DESC — longer phrases matched first
            uksort($routing, fn($a, $b) => strlen($b) - strlen($a));

            foreach ($routing as $keyword => $datasetKey) {
                if (str_contains($queryLower, strtolower($keyword))) {
                    if ($this->registry->has($datasetKey)) {
                        Log::debug('[NaturalQuery] Route matched', ['keyword' => $keyword, 'dataset' => $datasetKey]);
                        return $datasetKey;
                    }
                }
            }
        }

        // Priority 2: Schema aliases (longest first for best match)
        foreach ($this->registry->all() as $key => $schema) {
            if (str_contains($queryLower, strtolower($key))) {
                return $key;
            }

            $aliases = $schema['aliases'] ?? [];
            usort($aliases, fn($a, $b) => strlen($b) - strlen($a));

            foreach ($aliases as $alias) {
                if (str_contains($queryLower, strtolower($alias))) {
                    return $key;
                }
            }
        }

        // Priority 3: Column aliases
        foreach ($this->registry->all() as $key => $schema) {
            $columns = $schema['tables']['primary']['columns'] ?? [];
            foreach ($columns as $colName => $colDef) {
                foreach ($colDef['aliases'] ?? [] as $alias) {
                    if (str_contains($queryLower, strtolower($alias))) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Every dataset the question's text and the project's own config point
     * at — not just the first. Feeds `SchemaShortlister::resolve()`, which
     * needs the full set of seeds to build a scope that answers the whole
     * question rather than the part named first.
     *
     * MERGED, not cascaded (NQ-001-v3, defect 1). This used to be
     * `routingSeeds() ?: schemaAliasSeeds() ?: columnAliasSeeds()` — the
     * instant ANY `query_routing` keyword matched, every alias match was
     * discarded outright, even one naming a totally different, unrelated
     * dataset by its own alias, in plain words, in the same sentence. "which
     * products bring in the most revenue", with `revenue => orders` routed,
     * silently dropped `products` — a dataset the question NAMES OUTRIGHT —
     * and R2 then omitted it from the prompt with nothing to say so. That is
     * a scope too NARROW to answer the question actually asked, and no
     * downstream step can recover a dataset the prompt never rendered.
     *
     * The trade this makes instead, per §0: a routing match can no longer
     * SUPPRESS an incidental alias mention elsewhere in the same sentence.
     * "total revenue from the gizmo line, excluding cancelled ORDERS" now
     * also seeds the orders dataset, even though "orders" there reads more
     * like a filter than a second topic. That widens the prompt — at worst
     * costing a bigger render or a loud, actionable budget refusal — but it
     * is never a SILENT cost: every dataset this seeds is rendered in FULL
     * (R1), required_filter included, so a wrong answer built from it is a
     * model failing to read what it was plainly shown, not a table it was
     * never shown at all. A scope too narrow risks a confidently wrong
     * number with no way back; a scope too wide risks nothing worse than
     * that, which is why §0 puts the two on opposite sides of the same
     * trade-off and prefers width every time.
     *
     * Every tier's matches count, not only the first within a tier — that is
     * what lets a question genuinely spanning two routed keywords seed both.
     *
     * `default_dataset` sits outside the merge entirely: config is supreme,
     * so a project's declared primary dataset stays in scope whether or not
     * the question matched anything else at all — the same priority it
     * already has in `QueryOrchestrator::query()`, which applies it before
     * keyword detection even runs.
     *
     * @return array<int, string> dataset keys, deduplicated, in match order
     */
    public function seeds(string $query): array
    {
        $queryLower = strtolower($query);
        $seeds = array_merge(
            $this->routingSeeds($queryLower),
            $this->schemaAliasSeeds($queryLower),
            $this->columnAliasSeeds($queryLower)
        );

        $default = config('naturalquery.default_dataset');
        if ($default && $this->registry->has($default)) {
            $seeds[] = $default;
        }

        return array_values(array_unique($seeds));
    }

    /** @return array<int, string> */
    protected function routingSeeds(string $queryLower): array
    {
        $seeds = [];

        foreach (config('naturalquery.query_routing', []) as $keyword => $datasetKey) {
            if (str_contains($queryLower, strtolower($keyword)) && $this->registry->has($datasetKey)) {
                $seeds[] = $datasetKey;
            }
        }

        return $seeds;
    }

    /** @return array<int, string> */
    protected function schemaAliasSeeds(string $queryLower): array
    {
        $seeds = [];

        foreach ($this->registry->all() as $key => $schema) {
            if (str_contains($queryLower, strtolower($key))) {
                $seeds[] = $key;
            }

            foreach ($schema['aliases'] ?? [] as $alias) {
                if (str_contains($queryLower, strtolower($alias))) {
                    $seeds[] = $key;
                }
            }
        }

        return $seeds;
    }

    /** @return array<int, string> */
    protected function columnAliasSeeds(string $queryLower): array
    {
        $seeds = [];

        foreach ($this->registry->all() as $key => $schema) {
            $columns = $schema['tables']['primary']['columns'] ?? [];
            foreach ($columns as $colDef) {
                foreach ($colDef['aliases'] ?? [] as $alias) {
                    if (str_contains($queryLower, strtolower($alias))) {
                        $seeds[] = $key;
                    }
                }
            }
        }

        return $seeds;
    }
}
