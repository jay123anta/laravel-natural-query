<?php

namespace Jayanta\NaturalQuery\Engine;

use Jayanta\NaturalQuery\Schema\SchemaRegistry;

/**
 * Schema Shortlister
 *
 * Decides which datasets a question's SQL-generation prompt is built from
 * (NQ-001-v2, bounding the multi-dataset prompt). A PURE function of
 * (seed keys, schema) — no provider, no question text, no budget input —
 * which is what makes `resolve()` never see the question at all (property 13):
 * every question-inspecting signal lives in `DatasetSeeder`, and this class
 * only ever expands keys it is handed.
 *
 * Two-tier only (R1): a dataset is in the returned scope, rendered in FULL by
 * `PromptBuilder`, or it is absent. There is no compact middle tier — one
 * does not exist for a dataset schema discovery never flagged a primary key
 * or foreign key column for, so a compact line could never carry either.
 *
 * `resolve()` never returns an EMPTY scope. A seed list that resolves to
 * nothing real falls OPEN to every dataset, with the reason recorded
 * (property 11) — a confidently narrow, possibly wrong, shortlist is worse
 * than the unbounded prompt this feature exists to bound.
 */
class SchemaShortlister
{
    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param array<int, string> $seedKeys dataset keys, exact or aliased, a
     *        model, a routing rule, or a previous turn's state named
     */
    public function resolve(array $seedKeys): PromptScope
    {
        $allKeys = $this->registry->keys();

        // Defect 1 fix: normalise each seed through has() THEN findByAlias()
        // — never has() alone. A model answering with an alias ("SL
        // Warehouses" rather than the schema key it names) had that dataset
        // silently dropped by the reverted attempt, which is worse than a
        // wholly invented seed: a PARTLY correct answer looked complete.
        $resolved = [];
        foreach ($seedKeys as $seed) {
            if (!is_string($seed) || $seed === '') {
                continue;
            }

            if ($this->registry->has($seed)) {
                $resolved[] = $seed;
            } elseif ($alias = $this->registry->findByAlias($seed)) {
                $resolved[] = $alias;
            }
        }

        $resolved = array_values(array_unique($resolved));

        if (empty($resolved)) {
            return new PromptScope(
                keys: $allKeys,
                omitted: [],
                scopedTables: $this->tableNamesFor($allKeys),
                source: 'all',
                reason: empty($seedKeys)
                    ? 'no seed datasets were supplied'
                    : 'none of the named datasets exist in this schema — falling open to every '
                        . 'dataset rather than guessing a narrower shortlist'
            );
        }

        // Seeds, then FK/join expansion, then stop (R3) — never influenced by
        // prompt size, only by what the question and the schema's own edges
        // say is relevant.
        $expanded = $this->registry->expand($resolved);

        // Registry order (not seed order), so PromptBuilder's own iteration —
        // already registry order — renders a scoped prompt identical in
        // layout to the unscoped one, just narrower.
        $keys = array_values(array_filter($allKeys, fn ($key) => in_array($key, $expanded, true)));
        $omitted = array_values(array_diff($allKeys, $keys));

        return new PromptScope(
            keys: $keys,
            omitted: $omitted,
            scopedTables: $this->tableNamesFor($keys),
            source: 'seeded',
            reason: count($keys) > count($resolved)
                ? 'seeded from ' . implode(', ', $resolved) . ', expanded one hop via foreign keys/required_join'
                : 'seeded from ' . implode(', ', $resolved)
        );
    }

    /** @param array<int, string> $keys @return array<int, string> */
    protected function tableNamesFor(array $keys): array
    {
        $tables = [];
        foreach ($keys as $key) {
            $name = $this->registry->getTableName($key);
            if ($name) {
                $tables[] = $name;
            }
        }

        return $tables;
    }
}
