# Caching

The query cache skips the **AI step**, never the query. A cached question still
runs its SQL against your database on every ask, so the numbers are always
current — what is saved is the API call, the latency and the cost.

That distinction matters when you are reading the hit rate: a cache hit is not
a stale answer, it is a fresh answer that did not need the model.

```env
NATURALQUERY_CACHE_ENABLED=true    # default
```

## What is stored

Two shapes, depending on how the question was answered.

| Mode | Stored | Replayed by |
|---|---|---|
| `intent` | The parsed intent — dataset, metric, filters, grouping, period | `SqlBuilder`, which rebuilds the SQL |
| `sql_generation` | The finished SQL, under `_sql_result` | Replayed verbatim |

The two are **not** interchangeable, and the engine will not read one as the
other. A SQL-generation row exists precisely because the question needed
something the intent contract cannot express — a `WHERE` predicate, a `HAVING`,
a ratio — and rebuilding it through that contract would drop exactly the part
that made it necessary. Handed a SQL row, intent mode treats it as a miss and
pays for a fresh call.

Nothing from your data is ever stored: the row holds the question text, the
structure the AI derived from it, and the SQL. No result rows, no values.

## Two tiers

**Tier 1** is your Laravel cache store (Redis, Memcached, file, database) keyed
by hash. It is checked first and honours `cache.ttl`.

**Tier 2** is the `naturalquery_cache` table, and it has two stages:

- **exact** — the same question after normalisation (lowercased, filler words
  dropped, synonyms folded, words sorted). "Show me the top 5 customers" and
  "top 5 customers" are the same key.
- **fuzzy** — `0.6 × Jaccard + 0.4 × Levenshtein`, above
  `cache.similarity_threshold` (default `0.85`).

Tier 2 rows do not expire. Use `naturalquery:cache-cleanup` to age them out.

### Do not lower the fuzzy threshold

It is a **lexical** comparison — shared words and edit distance. It does not
understand meaning. Measured on this implementation, "top 10 customers by
revenue" and **"bottom 10 customers by revenue"** score `0.65`, which is higher
than most genuine paraphrases score against each other. Any threshold low
enough to catch the paraphrases you want is also low enough to answer a
question with its opposite, from cache, with no API call to correct it.

Raise it for fewer reuses; set `cache.enabled` to `false` for none.

## Scope: when a row may be reused

The cache key is the question's **text**. Text alone is not enough to identify
a question, so a row also records the **dataset scope the asker had**, and is
only reused for a question asked under that same scope.

The scope comes from an explicit `dataset` argument, then conversation state,
then keyword detection on the question's own words, then — if exactly one
dataset is registered — that one. If none of those place the question, the
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

- **`dataset` column** — what the answer turned out to be about, i.e. the
  dataset the model chose. This is what `cache-cleanup --dataset` and the stats
  command read.
- **asking scope** — what the *question* was scoped to. This decides
  eligibility.

They routinely differ: an unscoped question still gets answered about some
specific dataset. Conflating them means an unscoped question can never match
its own cached row, and the cache goes dark for every question keyword
detection cannot place.

## Conversations are never cached

A follow-up is neither read from nor written to this cache. "Only in West"
means one thing after a revenue question and another after an order count, and
the key carries no session — so a row written by one conversation would be
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
php artisan naturalquery:cache-cleanup --dataset=orders    # one dataset
php artisan naturalquery:cache-cleanup --all               # everything, after a confirmation
```

A bare run is **not** a full clear: it applies both defaults (`--days=30`,
`--min-hits=2`), so it removes entries that are both old and rarely used. Use
`--all` to empty the cache.

`--dataset` matches the `dataset` column — the dataset the answer is about —
which is the right handle when a schema file changed and its cached answers are
now wrong.

## Replacing the cache

Bind your own implementation of `Contracts\QueryCacheInterface`:

```php
$this->app->bind(
    \Jayanta\NaturalQuery\Contracts\QueryCacheInterface::class,
    \App\Cache\MyQueryCache::class
);
```

Four methods: `find`, `store`, `getStatistics`, `clear`. Store the intent array
you are given **verbatim** and return it unchanged — the engine reads reserved
keys out of it (`_sql_result`, `_asking_scope`) and drops anything it does not
recognise, so you never need to know what is in there.

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
less safe — it just cannot narrow its own fuzzy search.

### Adding a tenant or permission gate

Subclassing the bundled `TwoTierQueryCache` and overriding `find()` works: when
`find()` is overridden and `findForDataset()` is not, the engine calls
`find()`, so your gate runs. You give up the fuzzy tier, which needs the scope
the other method carries.

To keep fuzzy matching, override **both**:

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
expecting a gate elsewhere to catch it, is not — the cache is consulted before
anything else in the pipeline runs.
