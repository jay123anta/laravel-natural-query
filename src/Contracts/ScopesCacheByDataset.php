<?php

namespace Jayanta\NaturalQuery\Contracts;

/**
 * A cache that can be told which dataset the asking question is about.
 *
 * WHY THIS IS SEPARATE FROM QueryCacheInterface
 *
 * `find()` keys on the question's TEXT. That is fine until a caller supplies
 * a dataset out of band — the widget's `dataset="orders"` prop, or the API's
 * dataset field — because then two questions with identical wording are two
 * different questions. "What is the total" on an orders page and the same
 * words on a products page hash the same, hit the same row, and the second
 * one is answered with the first one's number. No provider call, nothing in
 * a log, and the answer looks exactly like a correct one.
 *
 * The fix needs the asking dataset to reach the cache, and the obvious way
 * to do that — add a parameter to `find()` — cannot be done in a patch
 * release. PHP requires an implementing class to match the interface
 * signature, so adding even an optional parameter makes every third-party
 * cache fatal on load. `Contracts\ReportsUsage` solved the same problem the
 * same way when token reporting was added: a separate, optional interface,
 * checked with `instanceof`, degrading gracefully when absent.
 *
 * DEGRADING GRACEFULLY HERE MEANS SKIPPING THE CACHE, NOT IGNORING THE HINT
 *
 * A cache that does not implement this cannot be told the dataset, so when a
 * hint IS present the engine bypasses it rather than accepting a lookup that
 * cannot account for it. A miss costs one API call; a hit that answers about
 * the wrong table costs a wrong number, and AGENTS.md §0 ranks those nowhere
 * near each other. Custom caches keep working unchanged — they simply stop
 * being consulted on the questions where they could be wrong, until they opt
 * in by implementing this.
 */
interface ScopesCacheByDataset
{
    /**
     * Find a cached result for a query asked about a specific dataset.
     *
     * @param string $query The natural language query
     * @param string|null $datasetHint The dataset THIS question resolves to,
     *        when it is known at zero API cost — an explicit hint,
     *        conversation state, or keyword detection on the question's own
     *        text. Never the dataset of a candidate row: that is the thing
     *        being checked, not the thing doing the checking. With no hint,
     *        an implementation must refuse a fuzzy match rather than guess
     *        which dataset the asking question was about.
     * @return array|null Cached intent data, or null if not found
     */
    public function findForDataset(string $query, ?string $datasetHint = null): ?array;
}
