# Caching

The query cache skips the **AI step**, never the query. A cached question still
runs its SQL against your database on every ask, so the numbers are always
current -  what is saved is the API call, the latency and the cost.

That distinction matters when you are reading the hit rate: a cache hit is not
a stale answer, it is a fresh answer that did not need the model.

```env
NATURALQUERY_CACHE_ENABLED=true    # default
```

## What is stored

Two shapes, depending on how the question was answered.

| Mode | Stored | Replayed by |
|---|---|---|
| `intent` | The parsed intent -  dataset, metric, filters, grouping | `SqlBuilder`, which rebuilds the SQL |
| `sql_generation` | The finished SQL, under `_sql_result` | Replayed verbatim |

The two are **not** interchangeable, and the engine will not read one as the
other. A SQL-generation row exists precisely because the question needed
something the intent contract cannot express -  a `WHERE` predicate, a `HAVING`,
a ratio -  and rebuilding it through that contract would drop exactly the part
that made it necessary. Handed a SQL row, intent mode treats it as a miss and
pays for a fresh call.

Nothing from your data is ever stored: the row holds the question text, the
structure the AI derived from it, and the SQL. No result rows, no values.

## Dated questions are never cached

Since 2.2.1, a question whose answer covers a period is answered by the model
every time. It is the one thing the cache cannot do safely.

The key is the question's **words**, and those do not change when the correct
answer does. "Revenue last month" asked in July resolves to June; asked in
August it means July. Cached, the August ask replayed June -  the wrong number,
with no provider call, no expiry to age it out, and the fuzzy tier handing the
same dead row to every rewording. There was no way for the user to escape it
and nothing in the answer that looked wrong.

Both routes are covered, and both are judged on the **SQL that ran**:

- `intent` -  an intent carrying `date_from` or `date_to` is not stored.
- `sql_generation` -  a recipe whose SQL pins a moment is not stored. The test
  is a four-digit year anywhere in the statement, which catches the literal
  `'2026-06-01'` and the bare comparison in `YEAR(placed_on) = 2026`.

`NOW()`, `CURRENT_DATE` and friends re-evaluate on every execution, so a recipe
built on them stays correct next month and stays cacheable.

The check is deliberately blunt in the safe direction: a false positive costs
one API call, a false negative serves a wrong number for ever. Refusals are
logged at debug level (`Not caching a recipe that pins a date`) if you want to
see how often it fires.

**Upgrading:** rows written before 2.2.1 carry those dates and are retired by
the intent contract version, so they stop being served the moment you upgrade.
You do not need to run `naturalquery:cache-cleanup`, though it will reclaim
the space.

## Two tiers

**Tier 1** is your Laravel cache store (Redis, Memcached, file, database) keyed
by hash. It is checked first and honours `cache.ttl`.

**Tier 2** is the `naturalquery_cache` table, and it has two stages:

- **exact** -  the same question after normalisation (lowercased, filler words
  dropped, synonyms folded, words sorted). "Show me the top 5 customers" and
  "top 5 customers" are the same key.
- **fuzzy** -  `0.6 × Jaccard + 0.4 × Levenshtein`, above
  `cache.similarity_threshold` (default `0.85`). **Off by default** since
  2.1.0 -  see below.

Tier 2 rows do not expire. Use `naturalquery:cache-cleanup` to age them out.

### Fuzzy matching is opt-in, and why

```env
NATURALQUERY_CACHE_FUZZY=true    # default false
```

**It matches typos, and nothing else.** Since 2.3.0 a fuzzy hit means the two
questions differ by exactly one token on each side, and those two tokens are
plausible misspellings of one another — one edit, or one adjacent
transposition. Never a token carrying a digit, and never one shorter than four
characters.

So it reuses an answer for `custmers`, `pendign` and `regions`, and refuses
`grade a` for `grade b`, `2025` for `2026`, `top` for `bottom`, and
`last month` for `this month`.

### Why it is not a similarity score any more

Queries are normalised before comparison: lowercased, filler words dropped,
synonyms folded, de-duplicated and **sorted**. Two that come out equal are
already an exact hit. So every pair this tier judges differs in real,
meaning-bearing tokens — and a similarity score is dominated by the tokens they
*share*, which means it climbs as a question gets longer and more specific.

Measured on the old scorer, at the `0.85` the package shipped:

| Question pair | Score | Old behaviour |
|---|---|---|
| `revenue for grade a` / `grade b` | 0.739 | missed |
| `…by region for pending orders in grade a` / `grade b` | **0.883** | **reused — wrong answer** |
| `…and category…in 2025 for grade a` / `grade b` | **0.892** | **reused — wrong answer** |
| `revenue in 2025` / `in 2026` | 0.673 | missed |
| `top 10 customers` / `bottom 10 customers` | 0.468 | missed |
| `revenue summry…` / `revenue summary…` (a typo) | 0.818 | missed |
| `revenue summary for the orders channel` / `orders channel revenue overview` | 0.447 | missed |

The pairs that must never match scored **higher** than every pair that should.
No threshold separates those two sets, so at its own default this tier reused
the one thing it had to refuse and refused both things it existed for.

Deciding on the **difference** rather than the overlap inverts that, and it
needs no model, no embedding service and no network call.

### What it costs

**Genuine paraphrases no longer hit.** `revenue summary for the orders channel`
and `orders channel revenue overview` now cost a provider call. They have to:
`summary` and `overview` are two unrelated words in one slot, and so are
`grade a` and `grade b` — nothing lexical tells those apart.

At the shipped default of `0.85` that paraphrase already missed, so nobody
running the defaults loses anything. Only an install that had lowered
`similarity_threshold` to around `0.55` was being served it — and that install
was also being served `grade a` for `grade b`.

**Paraphrases belong in the synonym map**, where they are folded during
normalisation and become *exact* hits that nothing has to guess at.

`similarity_threshold` no longer decides a match. It is still read so a
published config keeps loading, and is used only to rank candidates that have
already been judged safe.

Still **off by default**: it is a real behaviour change, and the evidence for
flipping a default should come from installs rather than from the author.

## Scope: when a row may be reused

The cache key is the question's **text**. Text alone is not enough to identify
a question, so a row also records the **dataset scope the asker had**, and is
only reused for a question asked under that same scope.

The scope comes from an explicit `dataset` argument, then conversation state,
then keyword detection on the question's own words, then -  if exactly one
dataset is registered -  that one. If none of those place the question, the
scope is *unscoped*, which is itself a scope: an unscoped question matches
unscoped rows, and only those.

This is what stops the failure the guard exists for. "What is the total"
asked from a dataset-scoped page and the same words typed into a general chat
box are different questions, and answering the second from the first's row
returns another table's number with `success`, no API call and nothing in the
log.

A scope mismatch is always a **miss**, never a correction. Re-pointing a cached
row at a different dataset was tried and withdrawn: it turns a wrong table into
a wrong number, because a re-pointed recipe silently loses whatever made the
original question need it.

### Scope is not the answer's dataset

Two different values, deliberately kept apart:

- **`dataset` column** -  what the answer turned out to be about, i.e. the
  dataset the model chose. This is what `cache-cleanup --dataset` and the stats
  command read.
- **asking scope** -  what the *question* was scoped to. This decides
  eligibility.

They routinely differ: an unscoped question still gets answered about some
specific dataset. Conflating them means an unscoped question can never match
its own cached row, and the cache goes dark for every question keyword
detection cannot place.

## Conversations are never cached

A follow-up is neither read from nor written to this cache. "Only in West"
means one thing after a revenue question and another after an order count, and
the key carries no session -  so a row written by one conversation would be
served to every other conversation that used the same follow-up words.

See [CONVERSATIONS.md](CONVERSATIONS.md).

## Contract version

Rows record the intent-contract version they were written against, and a row
from an older contract is ignored rather than served.

| Version | Shipped in | Why |
|---|---|---|
| 3 | 2.0.0 | `scheme` renamed to `dataset`; the rename does not reach inside a stored JSON blob |
| 4 | 2.1.0 | Rows gained an asking scope; reading its absence as "unscoped" would reopen the cross-dataset hit |

Upgrading therefore costs one round of misses. Nothing needs clearing and no
action is required.

## Cleanup

```bash
php artisan naturalquery:cache-cleanup                     # not hit in 30 days AND under 2 hits
php artisan naturalquery:cache-cleanup --days=7            # change the age
php artisan naturalquery:cache-cleanup --min-hits=5        # keep only what gets reused often
php artisan naturalquery:cache-cleanup --dataset=orders    # every row for that dataset
php artisan naturalquery:cache-cleanup --dataset=orders --days=7   # narrowed
php artisan naturalquery:cache-cleanup --all               # everything, after a confirmation
```

A bare run is **not** a full clear: it applies both defaults (`--days=30`,
`--min-hits=2`), so it removes only entries that are both old and rarely used.
Use `--all` to empty the cache.

Naming a dataset is a different request, and the defaults do **not** apply to
it: `--dataset=orders` removes every row for that dataset, however fresh or
popular. That is what the flag is for -  a schema file changed and its cached
answers are now wrong, which describes rows being hit daily, not rows that have
gone quiet. Add `--days` or `--min-hits` explicitly to narrow a dataset purge.

`--dataset` matches the `dataset` column, the dataset the answer is about.

## Replacing the cache

Bind your own implementation of `Contracts\QueryCacheInterface`:

```php
$this->app->bind(
    \Jayanta\NaturalQuery\Contracts\QueryCacheInterface::class,
    \App\Cache\MyQueryCache::class
);
```

Four methods: `find`, `store`, `getStatistics`, `clear`.

`store($query, $intent)` hands you an array. Keep it **verbatim** -  the engine
reads reserved keys back out of it (`_sql_result` decides which reader may
replay the row, `_asking_scope` decides whether the row is eligible at all) and
ignores anything it does not recognise, so you never need to know what is
inside. A row that has lost those keys is not refused, it is misread.

`find($query)` returns that array **wrapped**, or `null`:

```php
return [
    'cached'           => true,
    'cache_match_type' => 'exact',              // or 'fuzzy'; reported as metadata
    'intent'           => $storedIntentArray,   // byte-identical to what store() got
    'dataset'          => $storedIntentArray['dataset'] ?? null,
];
```

Returning the bare intent array instead of the wrapper is the easy mistake and
it fails silently: the engine looks for `['intent']`, finds nothing usable, and
treats every lookup as a miss. The cache fills up and never serves anything.

### Optional: `ScopesCacheByDataset`

Implement it to be told what the asking question resolves to, so you can filter
your own fuzzy tier:

```php
use Jayanta\NaturalQuery\Contracts\ScopesCacheByDataset;

class MyQueryCache implements QueryCacheInterface, ScopesCacheByDataset
{
    public function findForDataset(string $query, ?string $datasetHint = null): ?array
    {
        // ...
    }
}
```

This is an **optimisation, not a safety mechanism**. Scope eligibility is
enforced by the engine either way, so a cache that does not implement it is not
less safe -  it just cannot narrow its own fuzzy search.

### Adding a tenant or permission gate

**Override both methods.** Overriding only `find()` is safe but expensive, and
the cost is total rather than partial.

When `find()` is overridden and `findForDataset()` is not, the engine calls
`find()` so your gate still runs -  that is the right trade (a skipped tenant
gate is a cross-tenant read; a cold cache is only slow). But `find()` looks up
with no dataset scope, while rows are stored under the scope their question was
asked with, so **nothing matches** and the hit rate is zero, not merely reduced.
The engine logs a warning naming your class when it detects this shape.

So override both:

```php
class TenantScopedCache extends TwoTierQueryCache
{
    public function find(string $query): ?array
    {
        return $this->gate(parent::find($query));
    }

    public function findForDataset(string $query, ?string $hint = null): ?array
    {
        return $this->gate(parent::findForDataset($query, $hint));
    }
}
```

Overriding only `findForDataset()` is also fine. Overriding neither, and
expecting a gate elsewhere to catch it, is not -  the cache is consulted before
anything else in the pipeline runs.
