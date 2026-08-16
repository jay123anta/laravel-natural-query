# Changelog

All notable changes to `jayanta/laravel-natural-query` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Every answer says how the question was understood.** `parsed_summary` is a
  one-line rendering of `parsed_query` — *"Orders · revenue · by region ·
  status is pending"* — and the bundled widget shows it under each answer.
  Previously only conversation follow-ups got such a line, so the very first
  question anyone asks displayed nothing. Roughly one question in five is
  misread on an uncurated schema, and a misreading that is shown is one the
  user can correct.

  In `sql_generation` mode a filter lives inside the generated SQL rather than
  in a structured field, so the line names the dataset and measure but may not
  name the filter. `intent` mode always names it.

- **`naturalquery:benchmark`** — measure accuracy on **your** schema. The
  package quotes a figure about itself; nobody should take that on trust for
  their own data. Supply a file of questions with the SQL you would have
  written by hand, and both queries run against your database with the result
  sets compared — aliases and column order ignored, row order ignored unless
  the question asked for a ranking.

  The point is the before-and-after: run it, run `audit-schema`, write the
  descriptions, run it again. The difference is what curation bought you, in a
  number you produced yourself. A wrong answer reports what the question was
  *read as*, which is usually where the misreading is.

  `--min=` for a CI gate, `--dataset=` to narrow, `--json` to chart it. Costs
  real provider calls and confirms before spending them. Reference SQL must be
  `SELECT`/`WITH` — a benchmark file gets copied between projects and a stray
  `UPDATE` would otherwise run unremarked. Example at
  `stubs/benchmark-example.php`.

  Grading uses the same `Benchmark\ResultComparator` as the package's own
  benchmark, so the two numbers mean the same thing.

- **`naturalquery:audit-schema`** — the curation coach. It also asks for a
  `required_filter` when a filterable column declares values like `cancelled`,
  `void` or `deleted` and the table has no rule — the one gap whose absence
  produces a wrong number rather than a vague answer. Roughly one question in
  five is misread on an uncurated schema, and the same questions land
  near-perfectly once a handful of sentences are written; until now nobody
  could see *which* sentences were missing. It reports only what introspection
  genuinely cannot recover:

  - a term two datasets both claim (`"amount" could mean nq_orders.amount or
    nq_invoices.amount`) — the ambiguity that returns a plausible wrong number
    rather than an error
  - columns with no description, where the model has only the name to go on
  - datasets with no aliases, which users will never address by key
  - aggregatable columns with no unit, so `1500` renders bare
  - infrastructure tables (`sessions`, `jobs`, `audit_log`) still registered

  `--dataset=` to narrow, `--json` for CI. Exits 0 even with findings — an
  unaudited schema is not a broken install.

- **`naturalquery:cache-stats`** — what the cache is actually doing: distinct
  questions cached, answers reused, provider calls avoided, most-reused
  questions. `--json` for monitoring. This matters more now that fuzzy matching
  is off by default and rows are scoped, both of which lower hit rates on
  purpose; without numbers an operator cannot tell "correctly conservative"
  from "silently broken".

- **`naturalquery:doctor` reports published-config drift.** Laravel merges
  package config one level deep, so an app that published
  `config/naturalquery.php` under an earlier version silently loses every key
  added inside a block since. Doctor now names the missing settings. Two
  settings hit this in 2.1.0 alone, and one of them fails towards a wrong
  answer rather than an error.

- **`naturalquery:doctor` flags small models.** Measured on this package's own
  battery: Llama 3.1 8B scores 12/17, dropping filters and ignoring date
  periods; 70B-class and current hosted models score 17/17. Someone running an
  8B model locally and seeing wrong answers would otherwise blame the package.
  The check matches explicit parameter counts only — not words like "mini",
  which would catch capable hosted models this package has never measured.

### Fixed
- **`required_filter` was enforced on one route and merely suggested on the
  other.** It is how you state a rule the schema cannot imply — cancelled
  orders never count towards a total. `intent` mode appended it to the SQL so
  the model could not omit it; `sql_generation` put a line in the prompt and
  hoped. A model that skipped that line returned every cancelled row in the
  total, reported success, and nothing downstream re-checked — on the one
  setting whose whole purpose is that the answer is wrong without it.

  Generated SQL is now checked for the filter and **refused** if it is missing,
  on both the first generation and the refinement retry. Refused rather than
  patched: splicing a filter into arbitrary model SQL means a regex on the
  first `WHERE`, which on a query with a subquery in the `FROM` clause narrows
  the wrong thing, silently. The check is literal, so an equivalent filter
  written differently (`status NOT IN ('cancelled')`) is refused too — a false
  refusal costs an error message, a loose check costs a wrong number.

  `required_filter` is also now documented, in `docs/SCHEMA.md`. It had existed
  since 1.0.0 as a commented-out line in a stub file and appeared in no
  documentation at all — the most correctness-critical setting in the package
  was the hardest one to find.

- **`QueryState::fromIntent()` dropped the `filters` slot** although `summary()`
  reads it, so every state built from an intent described a filtered question
  exactly like an unfiltered one, and a new-query turn in a conversation lost
  the filter it had just been given.

## [2.1.0] - 2026-08-16

### Added
- **`Contracts\ScopesCacheByDataset`** — a new optional capability interface. A
  cache that implements it is asked `findForDataset($query, $datasetHint)` and
  can filter its own fuzzy tier; one that does not is called through
  `find()` exactly as before. Nothing existing has to change: it is a
  capability check, not a widening of `QueryCacheInterface`, because adding
  even an optional parameter to an interface method is a fatal error at class
  load for every implementation already in the wild.

- **`prompts.max_chars`** bounds the SQL-generation prompt, in characters —
  the single-dataset and multi-dataset forms alike, whichever the question
  builds. `null` (default) = unbounded, today's behaviour byte for byte
  — upgrading never changes anything unless you set this yourself. When a
  question's prompt would exceed it, the call is refused with an actionable
  message (bytes needed, bytes allowed, the config key to raise) BEFORE any
  request reaches the AI provider, rather than silently sending a truncated
  prompt.

  **Intent mode is not bounded by this.** `query_mode: intent`, and the intent
  half of `auto`, build their prompt in the provider and never consult
  `PromptBudget`. Ollama has its own pre-flight guard (`num_ctx`), so a
  self-hosted install is still protected there; a hosted provider will accept
  an oversized intent prompt and truncate or bill for it. Setting `max_chars`
  does not change that, and this is a known gap rather than an oversight in
  the wording.

  This is a **size bound only** — it does not narrow which datasets are
  sent. An earlier iteration of this feature attempted to also shortlist the
  dataset(s) a question's prompt is built from; that half was withdrawn
  before release. Deciding a dimension table one join away is or is not
  relevant to a question is a guess about someone's domain, which this
  package will not make, and the guess was demonstrably capable of changing
  a correctly-grouped answer's grouping while still reporting `success`. On
  a schema too large for `max_chars` to hold, the fix is a smaller
  schema/`system_instructions` footprint, `query_mode: intent` for the
  affected questions, or raising the limit — not scoping.

### Changed
- The dataset-index line format used by every provider's intent prompt now
  lives in one class, `Schema\DatasetCatalog`, instead of being open-coded in
  `AbstractProvider`. Output is byte-identical — verified across 29 hand-built
  shapes and 20,000 generated ones against the previous implementation, not
  merely asserted by a test.

### Fixed

- **A cached answer could come from the wrong table.** The query cache is keyed
  on the question's TEXT, and nothing else was checked before replaying a row.
  So the same wording asked in two places — a dataset-scoped page and a general
  one, two tenants, two schemas — was answered from whichever row was written
  first: right shape, wrong table, `success`, no API call, and nothing in the
  log to suggest anything had happened. Tier 2 rows have no TTL, so it did not
  age out.

  A row now records the SCOPE the question was asked under, and a row asked
  under a different scope is a MISS, never a correction. An earlier version of
  this fix retargeted the mismatch instead — overwriting the cached dataset
  with the current one — which turned a wrong table into a wrong number,
  because a re-pointed recipe silently loses whatever made the original
  question need it.

  Four routes had to be closed rather than one: the exact-hash tier, the fuzzy
  tier, the conversation path, and the case where the asking scope cannot be
  determined at all. Each earlier attempt closed the route it was written for
  and left the next one open.

- **The cache was off for most questions on a multi-dataset install.** A
  question naming no dataset resolves to no scope, while its cached row
  recorded the dataset the model had CHOSEN, which is never null — so the row
  could never match the question that created it and every repeat paid for
  another API call. The two values are now stored separately: `dataset` is what
  the answer is about, and is what `naturalquery:cache-cleanup --dataset` and
  the stats command read; the asker's scope is what decides eligibility.

- **`naturalquery:cache-cleanup --dataset=X` deleted nothing.** Two independent
  causes, both fixed.

  `store()` rewrote the intent blob and the contract version and left the
  derived columns alone, so `dataset` kept its first value once the same
  wording was asked about a second dataset. All derived columns now follow the
  intent.

  And the command ANDed its `--days=30` and `--min-hits=2` defaults in behind
  `--dataset`, so the flag could only ever remove rows that were simultaneously
  stale and barely used — while the reason to reach for it, a schema file
  changed and its cached answers are now wrong, describes rows being hit daily.
  Naming a dataset now purges that dataset; pass `--days` or `--min-hits`
  explicitly to narrow it. A bare run keeps both conservative defaults.

- **A conversation turn reported itself as cached.** `metadata.cache_hit` was
  set the moment the cache returned a row, before either reader had decided
  whether to use one — and both readers refuse a row mid-conversation. So a
  turn the provider had just generated and billed for came back `cache_hit:
  true`, and `verification.skip_on_cache_hit` (default true) read that flag and
  skipped `QueryVerifier` on brand-new SQL. The audit log and the
  `QuestionAnswered` event carried the same wrong value. The flag is now set
  only where a row is actually replayed.

- **A cached SQL recipe read back in intent mode lost its WHERE clause.**
  SQL-generation rows carry the finished statement; intent mode rebuilt them
  through `SqlBuilder`, whose contract has no slot for a predicate. A cached
  "revenue for pending orders" came back as the revenue for every row. Those
  rows exist precisely because the question needed something the intent
  contract cannot express, so rebuilding one through that contract was always
  going to drop exactly that. Intent mode now treats them as a miss.

- **A replacement cache was never read.** `findInCache()` bypassed any
  implementation that did not declare `ScopesCacheByDataset` whenever an asking
  dataset was known — and on a single-dataset install that is every question.
  `store()` kept working, so the adopter's cache filled up, returned nothing,
  and said nothing.

- **A tenant gate added by overriding `find()` never ran.** Subclassing the
  bundled cache and overriding `find()` is the obvious way to add a tenant or
  permission check; the subclass inherits `ScopesCacheByDataset`, so the engine
  called `findForDataset()` and went straight past it — a cross-tenant read
  that looks like a cache hit. Where `find()` is overridden and the scoped
  method is not, `find()` now wins.

- **Intent contract version raised to 4.** Rows written by 2.0.0 carry no
  scope, and reading that absence as "unscoped" would reopen the cross-dataset
  hit above. They are ignored rather than served. Expect one round of cache
  misses after upgrading; no action is required and nothing needs clearing.

- **A rate limit was reported as a rate limit on some paths and not others.**
  `error_code: rate_limited` maps to HTTP 429, `retryable: true` and a
  `Retry-After` header. Four paths never set it. A 429 while identifying the
  dataset was read as "could not place the question" and followed immediately
  by another call; a 429 inside a decomposed question let the remaining steps
  run, then returned an envelope with no `error_code` at all — so the
  controller answered **HTTP 500 with `retryable: false`**, telling every SDK
  and queue worker not to back off, on the one fault where backing off is the
  entire remedy. The bundled widget renders `data.error`, absent from that
  envelope too, so the user saw "The query could not be processed" with no
  mention of a rate limit. A 429 during planning did the same and then made a
  second call. All four now report it, and a limited run stops rather than
  continuing to ask.

- **Follow-up steps escaped the conversation.** A decomposed question re-entered
  the engine without its conversation context, so the steps could not see the
  metric, period or filters established earlier — and, because the cache guard
  keys on conversation state, each step wrote itself to the shared,
  session-less query cache. Another session asking those words read them back.

- **Fuzzy cache matching is now OFF by default** (`cache.fuzzy_matching`).
  Queries are normalised before comparison — sorted, de-duplicated, synonyms
  folded, filler words dropped — so any pair this tier judges already differs
  in meaning-bearing tokens, and the score is dominated by the tokens they
  share. Swapping one value in a long question scored 0.858 and 0.875 against
  a 0.85 threshold: the more precisely a question was stated, the likelier it
  was to be answered with a different one. No threshold setting fixes that.
  Re-enable with `NATURALQUERY_CACHE_FUZZY=true` if you accept the trade.

- **Generated SQL was cached before it was validated.** A statement rejected by
  `SqlValidator`, or refused by the database, was written to a cache with no
  expiry and replayed on every later ask of that wording — the provider never
  consulted again, so one bad generation made that question permanently
  unanswerable. Only SQL that validated and executed is cached now.

- **A question narrowed by a single character was answered from the
  un-narrowed one.** The cache key dropped every one-character token, so
  "total revenue for A" and "total revenue" shared a row: asking about one
  region returned the grand total, and asking for the total after that
  returned the region — `cache_hit: true`, no provider call, nothing in the
  answer to show it. Single-character values are ordinary — grade A, block B,
  zone 1 — and a token that changes the answer belongs in the key. `a` and `i`
  have also been removed from the filler-word list for the same reason; two
  phrasings differing only by an article now cost one extra API call, which is
  the correct trade against a wrong number.

- **A blank value in `.env` crashed every query.** `NATURALQUERY_PROMPT_MAX_CHARS=`
  with nothing after it is the empty string, not an absent value: `env()`'s
  default never fires and PHP 8 refuses a non-numeric string for a typed
  parameter, so `PromptBudget` threw a `TypeError` while the container was
  resolving `QueryOrchestrator` and the whole application 500'd.
  `NATURALQUERY_TIMEOUT=` did the same to every provider request. All twenty
  numeric settings now fall back to their documented default when the variable
  is absent, empty, or not a number.

  If you published `config/naturalquery.php` before this release, your copy
  still reads these through bare `env()`. Re-publish it, or leave the variables
  out of `.env` entirely rather than present-and-empty.

- **The Ollama context guard was being undone by the retry path.** 2.0.0 added
  a refusal so an oversized prompt is never sent, because Ollama does not
  reject one — it discards the beginning, which is the schema, and answers from
  what is left. The refusal worked. What happened next did not.

  A refusal became `provider_error`, and `retry_on_failure` (default true) then
  retried with a *single-dataset* prompt, which is far smaller, clears the same
  guard, and gets answered for real. So a question that should have produced
  "raise num_ctx" produced a confident number computed from one table, under a
  prompt telling the model those were the only tables in the database.
  `metadata.retry` was the only trace.

  Reproduced end to end: four linked datasets, `num_ctx` 1700, multi-dataset
  prompt needing ~2,026 tokens (refused), single-dataset retry needing ~1,382
  (sent). The caller received `revenue: 500` — arithmetic on one table — instead
  of the refusal.

  A provider that declines locally now says so with `refused_before_sending`,
  and the orchestrator does not retry it. The distinction matters and is the
  whole fix: when the *model* returns something unusable, a simpler prompt
  genuinely is more likely to succeed and that retry is kept. When the request
  never left the machine, a smaller prompt is not a second attempt at the
  question — it is a first attempt at a narrower one.

  The field is documented on `LlmProviderInterface`, so any provider adding a
  pre-flight guard gets the same protection without touching the orchestrator.

  Affects anyone on Ollama whose schema outgrew `num_ctx` — the case 2.0.0's
  guard was written for.

## [2.0.0] - 2026-08-12

One rename, and a fix for a silent Ollama failure.

### Changed — BREAKING

- **`scheme` is now `dataset`, everywhere.**

  The word came in with the application this package was extracted from, where
  the things being queried really were government schemes. Here it means "one
  of the datasets you have described", and it sat one letter away from
  `schema` — which the package also uses, for the files that define those
  datasets, and which PostgreSQL uses for something else again. The codebase
  had 400 of one and 464 of the other, so a reader meeting
  `SchemaRegistry::getAvailableSchemes()` had no way to tell whether `scheme`
  was a typo, a Postgres schema, or a third thing.

  `dataset` was already the word in the prompts (`AVAILABLE DATASETS:`) and in
  the documentation prose. This finishes a rename that had only ever been
  half done.

  **`schema` keeps its meaning** — the schema files, `SchemaRegistry`, and
  database introspection are unchanged. Only `scheme` moved.

  | Was | Now |
  |---|---|
  | `GET /naturalquery/schemes` | `GET /naturalquery/datasets` |
  | `?scheme=` query parameter | `?dataset=` |
  | `scheme` in every response and in `parsed_query` | `dataset` |
  | `scheme_name` in responses | `dataset_name` |
  | `default_scheme` config key | `default_dataset` |
  | `NATURALQUERY_DEFAULT_SCHEME` | `NATURALQUERY_DEFAULT_DATASET` |
  | `NaturalQuery::query($q, $schemeHint)` | `$datasetHint` |
  | `NaturalQuery::getSchemeMetrics($key)` | `getDatasetMetrics($key)` |
  | `<x-naturalquery::widget scheme="orders" />` | `dataset="orders"` |
  | `scheme` column on the cache and feedback tables | `dataset` |
  | `"scheme"` in the intent JSON contract | `"dataset"` |
  | route name `naturalquery.schemes` | `naturalquery.datasets` |
  | `schemes` key in the `/datasets` response body | `datasets` |
  | `unique_schemes`, `top_schemes` in `/cache-stats` | `unique_datasets`, `top_datasets` |
  | `schemes_with_feedback` in `/feedback/stats` | `datasets_with_feedback` |
  | `POST /feedback` field `scheme` | `dataset` (still required) |
  | `LlmProviderInterface::parseIntent($text, $schemeList)` | `$datasetList` |
  | `QueryCacheInterface::clear(?string $scheme)` | `?string $dataset` |
  | `SchemaRegistry::getAvailableSchemes()` / `getSchemeListForLlm()` | `getAvailableDatasets()` / `getDatasetListForLlm()` |
  | `PromptBuilder::buildMultiSchemePrompt()` | `buildMultiDatasetPrompt()` |
  | `Events\QuestionAsked::$scheme` | `$dataset` |
  | `--scheme=` on `cache-cleanup` and `debug` | `--dataset=` |
  | widget JS option `scheme:` | `dataset:` |
  | conversation `state.scheme` | `state.dataset` |

  **Upgrading from 1.0.0:** run `php artisan migrate`. A guarded migration
  renames the columns, renames their indexes to match what a fresh install
  gets, and keeps your cached queries and submitted feedback. It is a no-op on
  a fresh install, and it reads your table names from config, so a renamed
  table is migrated too.

  Then rename the config key, the env variable and any widget prop, and update
  anything reading `scheme` or `scheme_name` out of a response.
  `naturalquery:doctor` reports a published config that still sets
  `default_scheme`, because Laravel merges package config only one level deep
  and the old key would otherwise sit there being silently ignored.

  There is no compatibility shim. With a rename this thorough, one that
  accepted both spellings would reintroduce exactly the ambiguity being
  removed.

- **Cached intents now carry the contract version they were written against.**

  Bumping `INTENT_CONTRACT_VERSION` was supposed to make an upgrade miss stale
  rows rather than serve them. It never did on the fuzzy tier: fuzzy matching
  finds rows by their normalized *text* and never touches the hash the version
  was folded into. So the exact lookup would miss an old row and the fuzzy
  lookup would hand back the same row immediately afterwards — which had
  quietly defeated the earlier `group_by` bump too.

  The cache table gains a `contract_version` column, written on store and
  required by both lookups. Rows from 1.0.0 have it null and are unreachable,
  which is the intended behaviour: the question is asked once more and
  re-cached under the current shape. This matters here because the column
  rename does not reach inside the stored intent, which is JSON and still says
  `scheme` — served to 2.0.0 it would read as a null dataset, and Tier 2 rows
  have no expiry, so those questions would have stayed broken indefinitely.

### Verified

- **The renamed intent contract was re-tested against live models, not canned
  responses.** This is the check the unit suite structurally cannot perform: the
  fixtures and the parser were renamed by the same command, so they agree with
  each other and would keep agreeing even if every real model returned the old
  field name. The failure being looked for was a model answering with `scheme`,
  which turns every question into a clarification.

  Seventeen cases, arithmetic on three seeded rows: Gemini 2.5 Flash, Claude
  Sonnet 5, DeepSeek v4 Flash, Mistral Large and Llama 3.3 70B all **17/17**.
  Llama 3.1 8B **12/17**, up from 11/17 — within run-to-run variance rather
  than an improvement to claim.

  All five 8B failures are the documented weakness: one dropped filter and four
  date periods, where it returns the whole table instead of the month asked
  for. Conversation state — narrowing, drill-down, rewind, new-topic — passes
  even there, because it is resolved in PHP rather than left to the model.

### Fixed
- **Ollama was reading a truncated schema and not saying so.** The driver set
  `num_predict` — how many tokens to write — and never `num_ctx`, how many it
  may read. So every install ran on Ollama's server default, 4096 in current
  builds and 2048 in older ones, and neither is a number this package chose.

  Ollama does not reject a prompt that exceeds it. It discards the beginning
  and answers from the rest. The beginning is the schema block, so the model
  answered from table definitions it had only partly seen, and nothing in the
  response showed it had happened. On a multi-table app this is reachable with
  an ordinary question.

  `num_ctx` is now sent explicitly and configurable as
  `llm.providers.ollama.num_ctx` or `OLLAMA_NUM_CTX`, defaulting to 8192. A
  prompt that will not fit is **refused rather than sent**, with an error
  naming the setting, the size needed and the size available.

  The fit estimate uses three bytes per token rather than the usual four. A
  schema prompt is mostly identifiers and punctuation, which tokenize worse
  than prose, and the count is in bytes — so a multibyte description costs
  about one token per three-byte character. Both push the same way, and
  under-estimating is what sends a prompt to be truncated.

  Values below 1024 are treated as unset. `OLLAMA_NUM_CTX=` with nothing after
  it arrives as `''`, casts to `0`, and would otherwise refuse every question
  with an error naming the setting the user had just edited.

## [1.0.0] - 2026-08-10

First release. Nothing to upgrade from.

Ask your database a question in English and get an answer, without a single row
of your data leaving your server. The model is sent your schema structure and
the question; it returns SQL; your server validates it, runs it locally and
formats the result.

### Added

**The engine**

- Two strategies — local intent parsing (`intent`) and LLM SQL generation
  (`sql_generation`) — plus an `auto` mode that falls back from the first to the
  second. Both cost one API call.
- **Privacy wall.** Only schema structure and the user's question are ever sent
  to a provider. Generated SQL runs locally and result rows never leave the
  server. Enforced by tests, not by intent.
- **Escalation beyond the intent contract.** A question whose wording needs SQL
  the slot contract cannot express — `HAVING`, a numeric filter, a comparison
  against an aggregate, an exclusion, `DISTINCT`, a ratio, several aggregates at
  once, a per-group top-N — goes straight to SQL generation rather than being
  answered as a narrower question.
- **Counting.** Every dataset has an implicit `record_count` metric, so "how
  many orders by status" is answered by counting rather than by whichever
  measure happens to be default.
- **Breakdowns and cross-column filters.** `group_by` decides what the rows are;
  `filter_column` says which column a filter value belongs to, so "quantity by
  customer for Grocery" filters on category while grouping by customer. A filter
  on the column being grouped by narrows it rather than being dropped. An
  unusable breakdown or filter is refused with the list of what is available.
- **Non-sum aggregates.** AVG, MIN and MAX escalate to SQL generation — unless
  the schema defines one as a `computed_metric`, which is where "average order
  value means `ROUND(AVG(amount), 2)`" belongs. Those stay in intent mode:
  no extra call, and deterministic.
- **Time periods.** `date_from` / `date_to`, with the column chosen by the
  schema's `date_column` — a table often has several dates and they answer
  different questions. Providers are told today's date, since a model cannot
  otherwise resolve "last month". Dates are pattern-checked and bound; a period
  that cannot be parsed is refused rather than ignored. Every answer reports the
  range it actually covered, including in SQL-generation mode.
- **Multi-step answers and suggested follow-ups.** A question needing several
  queries is split, answered per step and combined, with the arithmetic done in
  PHP on values already fetched. Follow-up suggestions are derived from the
  schema — no API call, and incapable of proposing a breakdown the validator
  would refuse.
- Two-tier query cache (exact + similarity) and a feedback store.

**Conversations**

- **Conversation as structured state.** Each turn is classified
  (`new_query` / `refinement` / `drill_down` / `reference`), merged into a slot
  object in PHP rather than by rewriting the question, and validated against the
  schema before any SQL is built.
- A follow-up that names **no** measure and **no** breakdown keeps the ones
  already established; one that names either still changes it. Frontier models
  carry that context unprompted — the guard is what makes conversations work on
  small open-weight models, the audience that most needs them.
- A bare narrowing inside a conversation ("only in Guwahati" after "total amount
  by city") is read as a filter on the column already grouped by. Asked cold,
  the same words still return the detail rows for one named record.
- `GET /conversation/{session}` reads the state back — it lives on the server,
  so without it a page reload leaves the next follow-up resolving against
  context the user can no longer see. `POST /conversation/{session}/rewind`
  restores an earlier turn. Refinements are capped and follow-ups never touch
  the query cache.
- **Each turn says whether it stood alone.** The widget shows *New topic*,
  *Follow-up*, *Drill-down* or *Same query*, tinted when context was carried.
  The state summary alone cannot convey it: "total amount by city" then
  "breakdown by client" both read `amount · by client` whether the measure was
  inherited or worked out afresh.

**The HTTP API**

- **[docs/API.md](docs/API.md)** — every endpoint and field, the error table
  with HTTP statuses and which codes are worth retrying, the conversation state
  shape and how a turn is classified, and the CORS/token setup a front end on
  another origin needs. Pinned by contract tests.
- **`error_code` on every failure**, with a matching HTTP status: 429 for a rate
  limit (with `Retry-After`), 502 for a provider fault, 422 for a question that
  cannot be answered, 400 for one that was refused. Plus `retryable`, so a
  client need not know which codes are transient. A provider that is unreachable
  is never reported as a question nobody understood.
- A failed provider call includes the provider's own explanation — truncated,
  whitespace-collapsed and run through the secret redactor. "HTTP 403" is a
  number; the same 403 carrying "your team doesn't have any credits yet" is a
  five-minute fix.
- **`/schemes` returns everything a "what can I ask?" panel needs** — metrics,
  dimensions, default dimension, date column and examples per dataset, in one
  call. `?scheme=` returns one, or 404 naming the ones that exist.
- **CORS and token auth are a supported setup**, with an `api` middleware
  preset and a `doctor` check. Without the CORS entry the browser blocks the
  response before your code runs, and it looks like a network fault rather than
  a policy one.
- **Events** — `QuestionAsked`, `QuestionAnswered`, `QuestionFailed`,
  `UnsafeSqlRejected` — so an application can attribute cost, alert on a
  provider outage and audit what is being answered badly. `QuestionAnswered`
  carries the SQL that ran (server-side only) and a **row count, never the
  rows**: events walk into log drivers, queue payloads and error trackers, which
  is the one direction this package exists to keep data out of. A clarification
  fires neither the answered nor the failed event — being asked which measure
  you meant is the system working.
- **Token counts.** `metadata.usage` reports what a question cost when the
  provider says, reading both dialects (Gemini `usageMetadata`, OpenAI `usage`),
  accumulated across every call one question took. Absent on a cache hit; absent
  rather than zero when unreported, because a zero understates a bill. Custom
  providers opt in via `Contracts\ReportsUsage` — deliberately separate from
  `LlmProviderInterface`, which would break every custom provider already
  written.

**Providers**

- Four built-in drivers — Gemini, OpenAI, Claude, Ollama — plus a universal
  OpenAI-compatible driver: DeepSeek, Groq, Mistral, OpenRouter, vLLM,
  LM Studio, LocalAI and llama.cpp work by adding an `llm.providers.<name>`
  block with a `base_url`. A model you run yourself is a first-class choice.
- Provider defaults are verified live, per Rule 5.

**Getting it working**

- `naturalquery:discover [--ai]` reads your database and writes one plain-PHP
  schema file per table. Every generated file is verified to parse, and every
  AI-suggested example query is validated and planned with `EXPLAIN` against the
  real database, so hallucinated columns are dropped before they ship.
- `naturalquery:discover --merge` refreshes the structural layer after a
  migration while keeping everything written by hand — descriptions, aliases,
  `llm_instructions`, `computed_metrics`, `example_queries`, per-column flags,
  `group_column`, `required_join`, `required_filter`. New columns appear,
  dropped columns are removed, and the change is reported.
- `naturalquery:doctor` — self-diagnosis for driver/key, provider model
  liveness, database connection, migrations, CA certificate store, and whether
  every table and column named in your schema files still exists. Prints the
  exact fix per problem, never prints the API key, exits non-zero. `--skip-api`
  uses no quota.
- `naturalquery:install`, `naturalquery:debug` and `naturalquery:cache-cleanup`.
- The install template is **not** a selectable dataset: the registry ignores an
  unedited `example.php`, so a fresh install cannot answer "total amount" with
  "no such table: schema". `doctor` still reports it from the directory, so the
  file does not vanish from view.

**The widget**

- `<x-naturalquery::widget />` — a complete assistant in one line, served at
  `{prefix}/widget.js` with no publish step. Laid out as a conversation:
  header, scrolling thread, composer pinned at the bottom, question right and
  answer left. A search box above a result reads as one question at a time, and
  people did not try follow-ups because nothing suggested they could.
- **Voice is the browser's.** English speech is recognised on the device and
  posted to `/text` as if typed. Nothing to configure, no audio leaves the
  machine, and it works with every LLM because the model only ever reads a
  sentence. Firefox has no speech API, so the microphone is hidden there and
  people type — which is why text input is never optional.
- `widget.language` selects an **English accent** (`en-US`, `en-GB`, `en-IN`,
  `en-AU`), which measurably changes recognition accuracy. Other locales are not
  supported: the browser will attempt them, but the prompts, schema text and
  answers are all English. Multilingual is a separate package with a speech
  pipeline of its own.
- Bar/table/card rendering, text-to-speech, clarification prompts, undo, a
  session that survives a page reload, and `height="auto"` to grow with content.
- Framework-level refusals say what happened: a 401, an expired session (419), a
  throttle (429) and a 500 each get their own message rather than one
  "Unexpected response status", and an HTML error page no longer crashes the
  parse. These are precisely the first-run failures.
- The widget is a reference implementation. Everything it does goes through the
  same public REST endpoints your own front end would use.

**Access and limits**

- **Authorization is a gate, not `auth` middleware**, following the pattern
  Telescope and Horizon use: a `viewNaturalQuery` gate you define decides,
  always; with no gate, `local` and `testing` are open so the package works the
  moment you install it; anywhere else needs a signed-in user. Refusals are
  **403 naming the gate**, never a redirect — a fresh Laravel app has no auth
  scaffolding, so `auth` had nowhere to redirect and the demo page returned 500
  while working exactly as configured. The check is appended whatever
  `routes.middleware` contains, since emptying that list is the first thing
  people do to make the widget public. `GET /widget.js` stays public.
- `limits.queries_per_day` (default 200 per person) alongside the rate limit,
  applied by the package so that customising `routes.middleware` cannot drop it.
  It counts questions, which is a rough proxy: a two-table schema and a
  fourteen-table schema differ by an order of magnitude in prompt tokens.

**Security**

- `InputGuard` before the provider — prompt injection, SQL-in-text,
  exfiltration, unicode bypass, resource abuse — and a SELECT-only
  `SqlValidator` with a schema-derived table whitelist after it. Every SQL path
  goes through the validator, including feedback-submitted corrections.
- The optional companion package `jayanta/laravel-ai-guard` is auto-detected and
  layered on top of `InputGuard`. Enforcement follows ai-guard's own `mode` and
  `confidence_threshold`, so installing it never silently changes what is
  refused; override with `privacy.ai_guard.enforce`.
- `guzzlehttp/guzzle` is floored at `^7.15.1`. The package makes authenticated
  outbound HTTP on every query, and earlier 7.x releases carry open advisories.

**Databases and platform**

- **PostgreSQL, MySQL, MariaDB and SQLite.** SQLite matters because
  `laravel new` creates a SQLite app, so it is the database most people trying
  the package already have. Structure comes from `sqlite_master` and the PRAGMA
  functions, with foreign keys whose target column is omitted resolved against
  the referenced primary key, composite keys paired by position, and declared
  types normalised by SQLite's affinity rules — date and boolean checked first,
  since both have NUMERIC affinity and a DATE column is meant as a date.
- Pluggable introspection: any other database works by implementing
  `Contracts\SchemaIntrospectorInterface` and registering it under
  `sql.introspectors`. The built-in map lives in code, not in the publishable
  config, so apps that published their config keep working across upgrades —
  Laravel merges package config only one level deep.
- **Laravel 12 and 13 on PHP 8.2–8.5.** Laravel 10 and 11 are intentionally not
  supported: both are past security support, so every published version carries
  advisories and Composer refuses to install them under its default
  `policy.advisories.block`. A version the package manager will not install is
  not a version worth claiming.

**How it is verified**

- CI across PHP 8.2–8.5 × Laravel 12/13, prefer-lowest jobs at both ends, a lint
  job, and integration jobs that execute the real generated SQL against
  PostgreSQL 16 and 18, MySQL 8.4 and 9, and MariaDB 11. Those jobs fail if
  their tests skip rather than run, because a skipped test is not a passing one.
- **A provider conformance battery** (`tests/Conformance`) — seventeen cases
  whose answers are arithmetic on three seeded rows: totals, counts, filters,
  averages, rankings, calendar periods, a decomposed comparison, the follow-up
  suggestions, and a conversation that narrows, drills down and rewinds. It
  exists because unit tests structurally cannot find what it finds — a canned
  response happily answers a request the real API would reject. CI runs it per
  provider from repository secrets and skips the ones not set, so a fork is
  never failed by a secret it cannot have.
- **Accuracy is measured, not asserted.** An execution-accuracy harness grades
  generated SQL against hand-written gold SQL by comparing result sets, the way
  Spider and BIRD do. Run with `NATURALQUERY_BENCHMARK=1`.
- 528 PHP tests and 57 widget tests.
- Installed from the **distributed zip** — not a source checkout — into a clean
  Laravel 13 app. That is a genuinely different path: the zip is what
  `.gitattributes` `export-ignore` leaves behind. Auto-discovery, all five
  commands, `install`, both migrations, `discover` against the app's own tables,
  `doctor` and all fourteen routes work from `vendor/`.

### Known limits

- **Roughly one question in five is wrong on an *uncurated* schema.** Spider dev
  benchmark: 29/36 (81%). On a described schema it is far better, which is why
  the schema files matter more than anything else you will do. Show users
  `parsed_query` so a misreading is visible.
- **Model size matters more than vendor.** Gemini 2.5 Flash, Claude Sonnet 5,
  DeepSeek v4 Flash, Mistral Large and Llama 3.3 70B all score 17/17 on the
  conformance battery. Llama 3.1 8B scores 11/17 — it misses filters, miscounts,
  and ignores date periods entirely. Use a 70B-class model or better wherever a
  wrong number matters.
- **No external adopter has used this yet**, which is what the release candidate
  label is for. Suitable for an internal tool where figures get sanity-checked;
  not yet for unattended reporting.

[Unreleased]: https://github.com/jay123anta/laravel-natural-query/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/jay123anta/laravel-natural-query/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/jay123anta/laravel-natural-query/releases/tag/v1.0.0
