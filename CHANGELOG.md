# Changelog

All notable changes to `jayanta/laravel-natural-query` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Each turn says whether it stood alone.** The widget shows *New topic*,
  *Follow-up*, *Drill-down* or *Same query* beside the state, tinted when
  context was carried, with the reason on hover.

  Raised by manual testing: two turns on screen and no way to tell whether the
  second inherited the first. The state summary cannot convey it — "total
  amount by city" then "breakdown by client" both read `amount · by client`
  whether the measure was inherited or worked out afresh. Identical text, two
  different things happening. `conversation.classification` was already in the
  response; only the widget was silent about it.

- **Events.** `QuestionAsked`, `QuestionAnswered`, `QuestionFailed` and
  `UnsafeSqlRejected`. The package answered questions and told nobody anything:
  there was no way to attribute cost to a user, alert on a provider outage, or
  review the questions being answered badly, short of patching it. Listening to
  none of them changes nothing.

  `QuestionAnswered` carries the SQL that ran (server-side only — it is
  deliberately absent from the HTTP response) and a **row count, never the
  rows**. Result rows on an event walk into log drivers, queue payloads and
  error trackers, which is the one direction this package exists to keep data
  out of. A clarification fires neither the answered nor the failed event —
  being asked which measure you meant is the system working.
- **Token counts.** `metadata.usage` reports what a question cost when the
  provider says, reading both dialects (Gemini `usageMetadata`, OpenAI
  `usage`). Counts accumulate across every call one question took — a fallback,
  a retry, the steps of a decomposed question — since those are one question to
  the user. Absent on a cache hit; absent rather than zero when unreported,
  because a zero understates a bill.

  `limits.queries_per_day` counts questions, which is a rough proxy: a question
  against a two-table schema and one against a fourteen-table schema differ by
  an order of magnitude in prompt tokens.

  Custom providers opt in via `Contracts\ReportsUsage`. Deliberately a separate
  interface — adding to `LlmProviderInterface` would break every custom
  provider already written.

### Changed
- **Authorization is now a gate, not `auth` middleware.** Walking the documented
  install on a virgin Laravel 13 app — require, install, migrate, key, discover —
  the first thing it does is fail: `/naturalquery/demo`, the page the README
  sends you to, returns **500 `RouteNotFoundException: Route [login] not
  defined`**. A fresh Laravel app has no auth scaffolding, so `auth` had nowhere
  to redirect. The package looked broken while working exactly as configured.

  Following the pattern Telescope and Horizon use for the same problem:

  - a `viewNaturalQuery` gate defined by your app decides, always — including
    when it says no in local;
  - no gate, `local` or `testing` → allowed, so the package works on install and
    an adopter's first feature test does not fail with 403;
  - no gate, anywhere else → an authenticated user is required, as before.

  Refusals are **403 naming the gate**, never a redirect. The check is appended
  by the package whatever `routes.middleware` contains, since emptying that list
  is the first thing people do to make the widget public. `GET /widget.js` stays
  public — a static file with no data and no key, and gating it only stops the
  widget rendering.

  **Upgrading:** a published `config/naturalquery.php` keeps `auth` and is
  unaffected. Remove it to get the gate behaviour, and define the gate if the
  app is more than you. New installs get `['web', 'throttle:60,1']`.

- `widget.language` is documented as an **English accent** selector (`en-US`,
  `en-GB`, `en-IN`, `en-AU`), which measurably changes recognition accuracy.
  Other locales are not supported: the browser will attempt them, but the
  prompts, schema text and answers are all English.

### Fixed
- **The widget turned every framework-level refusal into "Unexpected response
  status."** It read the HTTP status and threw it away. A 401, an expired
  session (419), a throttle (429) and a 500 all produced the same message,
  which describes nothing and suggests nothing — and these are precisely the
  first-run failures. Each now says what happened and what to do, and an HTML
  error page no longer crashes the parse.
- **A test was silently disabling the rest of the suite.** `InstallCommandTest`
  ran the real installer, which publishes `config/naturalquery.php` — and under
  Testbench `config_path()` points inside `vendor/orchestra/testbench-core`,
  which survives between runs. That published copy then **shadowed the package
  config for every test**, so the suite was asserting against a stale snapshot.
  Nothing failed; it just stopped testing the current code, which is the
  dangerous kind. The installer now runs against a temp directory and removes
  anything it creates.

- `composer.json` was missing `illuminate/view`, `illuminate/validation` and
  `ext-json`, all of which the package uses directly — Blade components and
  the demo view, `$request->validate()` in the controller, and JSON handling
  throughout. They resolve in practice because any Laravel app has them, but a
  package that declares granular `illuminate/*` dependencies should declare
  what it uses.
- The `NaturalQuery` facade's docblock had drifted from the class: `query()`
  gained a `$context` argument, and `getSchemeMetrics()` / `registry()` were
  missing. A facade docblock is what an IDE completes against, so a stale one
  is worse than none.

### Removed
- **Server-side speech-to-text, and the `POST /voice` endpoint with it.**

  Voice in this package means: the **browser** recognises English speech and
  posts text to `/text`. Nothing to configure, no audio leaving the user's
  machine, and it works with every LLM — local or hosted — because the model
  only ever reads a sentence. That was always the design; `/voice` was carried
  over from the application this package was extracted from and had grown into
  a transcription subsystem that did not belong here.

  Inbuilt speech processing is part of the planned multilingual package, which
  will have a pipeline of its own. This one stays an English-only natural
  language to SQL assistant, and the smaller surface is the point: it is meant
  to be the easy way for a developer to add NL→SQL, not a speech platform.

  Gone: `POST /voice`, `Contracts\TranscriberInterface`, the `Transcription`
  namespace, the `voice` config block, `NATURALQUERY_TRANSCRIBE_*`,
  `widget.server_voice`, and the widget's MediaRecorder upload.
  `LlmProviderInterface` no longer declares `parseVoiceQuery()` or
  `supportsVoice()`, and `health` no longer reports `provider.supports_voice`.

  Error codes `voice_unsupported` and `transcription_failed` are retired.

  **If you were using `/voice`:** use the browser's `SpeechRecognition` and post
  the transcript to `/text` — see [docs/API.md](docs/API.md#voice--there-is-no-audio-endpoint).
  Firefox has no speech API, so people type there.

## [1.0.0-rc.2] - 2026-08-08

Everything here rolls into 1.0.0. This release is about the HTTP API: the
bundled widget is a reference, and adopters build their own front ends in
React, Vue, Inertia or Blade — so for most of them the response shape *is* the
package.

Upgrading from rc.1 needs no code changes. Every change below is additive
except the two error codes noted under Fixed.

### Added
- **[docs/API.md](docs/API.md)** — the full HTTP reference. Every endpoint and
  field, the error table with HTTP statuses and which codes are worth retrying,
  the conversation state shape and how a turn is classified, and the CORS/token
  setup a front end on another origin needs.
- **`error_code` on every failure**, with an HTTP status to match: 429 for a
  rate limit (with `Retry-After`), 502 for a provider fault, 422 for a question
  that cannot be answered, 400 for one that was refused. Plus `retryable`, so a
  client need not know which codes are transient. Previously every failure was
  `status: error` with an English sentence and a status picked by whether a
  metadata key happened to be set.
- **`GET /conversation/{session}`** — read the state back. The state lives on
  the server, so without this a page reload leaves the next follow-up resolving
  against context the user can no longer see.
- **`POST /conversation/{session}/rewind`** now returns the same body as the
  state endpoint, including a fresh `can_rewind`.
- **`/schemes` returns everything needed for a "what can I ask?" panel** —
  metrics, dimensions, default dimension, date column and examples per dataset,
  in one call. `?scheme=` returns one, or 404 naming the ones that exist.
- **CORS and token auth are a supported setup**, not a discovery: an `api`
  middleware preset, documentation, and a `naturalquery:doctor` check. Without
  the CORS entry the browser blocks the response before your code runs, and it
  looks like a network fault rather than a policy one.
- **The widget is laid out as a conversation** — header, scrolling thread,
  composer pinned at the bottom, question right and answer left. A search box
  above a result reads as one question at a time, and people did not try
  follow-ups because nothing suggested they could. `height` sets the frame
  (`auto` grows with the content). Example queries live in the empty thread and
  clear once it starts.
- **Undo last step** in the widget, and the session now survives a page reload.
- `naturalquery:doctor` checks whether PHP has a CA certificate store at all.

### Fixed
- **An unreachable provider is no longer reported as a question nobody
  understood.** `success: false` from a provider never means the question was
  unclear — an unclear question is a *successful* call carrying a
  clarification. Both sites that confused the two now return `provider_error`
  with the provider's own message. Found when a lost CA bundle made all 36
  benchmark questions come back "Could not understand the query. Try mentioning
  a dataset name."
- **"Try mentioning a dataset name" is no longer the advice when the dataset
  was already identified.** That case returns `cannot_answer` and names the
  dataset back.
- One validation rule for a question wherever it arrives: `/text` demanded
  three characters while `/conversation` accepted one, so a follow-up like "no"
  was answered by one endpoint and rejected by the other.

### Changed
- Two error codes moved to more accurate values: a failed provider call reports
  `provider_error` (502) rather than `not_understood` (422), and an identified
  dataset that cannot answer reports `cannot_answer`. Clients branching on
  `error_code` for these two cases should check both.

Tests: 437 PHP, 43 widget. Benchmarks against a live provider: Spider dev
29/36 (81%), execution accuracy 10/14 (71%).

## [1.0.0] - unreleased

First public release.

### Added
- Natural language → SQL engine with two strategies: local intent parsing
  (`intent`) and LLM SQL generation (`sql_generation`), plus an `auto` mode
  that falls back from the first to the second.
- Privacy wall: only schema structure and the user's question are ever sent to
  a provider. Generated SQL runs locally; result rows never leave the server.
- Four built-in LLM drivers — Gemini, OpenAI, Claude, Ollama — plus a universal
  OpenAI-compatible driver: any hosted or self-hosted service (DeepSeek, Groq,
  Mistral, OpenRouter, vLLM, LM Studio, LocalAI, llama.cpp) works by adding an
  `llm.providers.<name>` block with a `base_url`.
- `naturalquery:discover [--ai]` — reads your database and writes one plain-PHP
  schema file per table. Every generated file is verified to parse, and every
  AI-suggested example query is validated and planned with `EXPLAIN` against
  the real database so hallucinated columns are dropped before they ship.
- `naturalquery:doctor` — self-diagnosis for driver/key, provider model
  liveness, database connection, migrations, and whether every table and column
  named in your schema files actually exists. Prints the exact fix per problem,
  never prints the API key, exits non-zero. `--skip-api` uses no quota.
- Drop-in widget: `<x-naturalquery::widget />`, served at `{prefix}/widget.js`
  with no publish step. Browser speech-to-text (English, on the device, works
  with every provider), text-to-speech, bar/table/card rendering, clarification
  prompts and multi-turn follow-ups.
- Two-tier query cache (exact + similarity), feedback store, conversation
  manager, and a `naturalquery:debug-prompt` command.
- Security: `InputGuard` (prompt injection, SQL-in-text, exfiltration, unicode
  bypass, resource abuse) before the provider, and SELECT-only `SqlValidator`
  with a schema-derived table whitelist after it.
- Optional companion package `jayanta/laravel-ai-guard` is auto-detected and
  layered on top of `InputGuard`. Enforcement follows ai-guard's own `mode` and
  `confidence_threshold`, so installing it never silently changes what is
  refused; override with `privacy.ai_guard.enforce`.
- Supports **Laravel 12 and 13** on **PHP 8.2–8.5**.
- CI across PHP 8.2–8.5 × Laravel 12/13, prefer-lowest jobs at both ends of the
  range, a lint job, and integration jobs that execute the real generated SQL
  against PostgreSQL 16 and 18, MySQL 8.4 and 9, and MariaDB 11. Those jobs
  fail if their tests skip rather than run, because a skipped test is not a
  passing test.

  Laravel 10 and 11 are intentionally not supported. Both are past security
  support, so every published version carries advisories and Composer refuses
  to install them under its default `policy.advisories.block`. A version the
  package manager will not install is not a version worth claiming.
- **SQLite is supported.** `laravel new` creates a SQLite app, so this is the
  database most people who try the package already have; installing it used to
  end in "go and set up MySQL first". Structure comes from `sqlite_master` and
  the PRAGMA functions, with foreign keys whose target column is omitted
  resolved against the referenced primary key, composite keys paired by
  position, and declared types normalised by SQLite's affinity rules — date and
  boolean checked first, since both have NUMERIC affinity and a DATE column is
  meant as a date.
- **Time periods.** `date_from` / `date_to` in the intent, with the column
  chosen by the schema's `date_column` — a table often has several dates and
  they answer different questions. Providers are told today's date, since a
  model cannot otherwise resolve "last month". Dates are pattern-checked and
  bound; a period that cannot be parsed is refused rather than ignored.
- **Escalation beyond the intent contract.** In `auto` mode a question whose
  wording needs SQL the contract cannot express — `HAVING`, a numeric filter, a
  comparison against an aggregate, an exclusion, `DISTINCT`, a ratio, several
  aggregates at once, a list of columns, a per-group top-N — goes straight to
  SQL generation instead of being answered as a narrower question. Costs
  nothing: both modes are one API call.
- **Counting.** Every dataset has an implicit `record_count` metric, so "how
  many orders by status" is answered by counting rather than by falling back to
  whichever measure is default.
- **Breakdowns and cross-column filters.** `group_by` decides what the rows
  are; `filter_column` says which column a filter value belongs to, so
  "quantity by customer for Grocery" filters on the category while grouping by
  the customer. An unusable breakdown or filter is refused with the list of
  what is available.
- **Conversation as structured state.** Each turn is classified
  (`new_query` / `refinement` / `drill_down` / `reference`), merged into a slot
  object in PHP rather than by rewriting the question, and validated against the
  schema before any SQL is built. Every answer reports the state it was
  understood as; `POST /conversation/{session}/rewind` restores an earlier turn;
  refinements are capped; follow-ups never touch the query cache.
- **Multi-step answers and suggested follow-ups.** A question needing several
  queries is split, answered per step and combined, with the arithmetic done in
  PHP on values already fetched. Every answer carries follow-up questions
  derived from the schema — no API call, and incapable of proposing a breakdown
  the validator would refuse.
- **Spending limits.** `limits.queries_per_day` (default 200 per person)
  alongside the existing rate limit, applied by the package so that customising
  `routes.middleware` cannot drop it.
- **Accuracy is measured, not asserted.** An execution-accuracy harness grades
  generated SQL against hand-written gold SQL by comparing result sets, the way
  Spider and BIRD do. Run with `NATURALQUERY_BENCHMARK=1`.
- Pluggable schema introspection: `sqlite`, `pgsql`, `mysql` and `mariadb` are built in,
  and any other database can be supported by implementing
  `Contracts\SchemaIntrospectorInterface` and registering it under
  `sql.introspectors`. The built-in map lives in code, not in the publishable
  config, so apps that published their config under an earlier version keep
  working across upgrades — Laravel merges package config only one level deep.
- `naturalquery:discover --merge` — after a migration, refresh the structural
  layer of an existing schema file while keeping everything you wrote by hand:
  descriptions, aliases, `llm_instructions`, `computed_metrics`,
  `example_queries`, per-column flags, `group_column`, `required_join`,
  `required_filter`. New columns appear, dropped columns are removed, and the
  change is reported. `--force` still regenerates from scratch.

### Notes

- **This is a first release; there is nothing to upgrade from.** The bugs found
  and fixed before publishing — including a case where a question about one
  specific record could be answered with the top-ranked row, and PostgreSQL-only
  `ILIKE` breaking named-record lookups on MySQL — are recorded in the
  repository history rather than here, because no published version ever
  carried them.
- Requires PostgreSQL or MySQL/MariaDB. **SQLite is not supported** — the
  engine introspects your schema — and Laravel 11+ defaults to it, so
  `naturalquery:doctor` reports that explicitly rather than letting queries
  fail mysteriously.

### Security
- `guzzlehttp/guzzle` is floored at `^7.15.1`. The package makes authenticated
  outbound HTTP on every query, and earlier 7.x releases carry open advisories.

[Unreleased]: https://github.com/jay123anta/laravel-natural-query/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/jay123anta/laravel-natural-query/releases/tag/v1.0.0
