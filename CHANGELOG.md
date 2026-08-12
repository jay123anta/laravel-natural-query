# Changelog

All notable changes to `jayanta/laravel-natural-query` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- The dataset-index line format used by every provider's intent prompt now
  lives in one class, `Schema\DatasetCatalog`, instead of being open-coded in
  `AbstractProvider`. Output is byte-identical — verified across 29 hand-built
  shapes and 20,000 generated ones against the previous implementation, not
  merely asserted by a test.

### Fixed

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
