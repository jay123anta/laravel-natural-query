# Changelog

All notable changes to `jayanta/laravel-natural-query` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **Server-side voice no longer depends on which LLM you chose.** Transcription
  is its own capability behind `Contracts\TranscriberInterface`, configured
  under `voice` in `config/naturalquery.php`. Point
  `NATURALQUERY_TRANSCRIBE_URL` at anything speaking the OpenAI
  `/audio/transcriptions` shape — a local whisper.cpp or faster-whisper server,
  LocalAI, LM Studio, Groq, OpenAI — and `/voice` works regardless of provider.

  Until now `/voice` only worked on Gemini, because Gemini accepts audio inline
  in the same call that returns intent JSON and the feature had been built
  around that. Every other provider was told it "does not support voice", which
  was true of the provider and false of what was possible: an app on Ollama,
  Claude or a self-hosted model had no route to voice at all — and self-hosted
  is the case with the best reason to want it, since a local Whisper server
  keeps the recording inside the network.

  Gemini's inline path is still available as the `provider` driver. It is now
  one option among several rather than the only one, and it was never buying a
  round trip anyway: the orchestrator already discarded the intent it returned
  and re-ran the query from the transcript.

### Added
- `voice.driver` — `auto` (a configured endpoint, else the provider's own audio
  support, else nothing), `openai_compatible`, `provider`, or `none`.
- `GET /health` reports `voice.enabled` and `voice.transcriber`. Read that
  rather than `provider.supports_voice` when deciding whether to offer a
  microphone: they disagree for every setup pairing a local Whisper server with
  an LLM that has no audio support, which is the common case.
- `/voice` answers now carry `transcribed_text`, so a wrong answer to a
  misheard question is recognisable as exactly that.
- `naturalquery:doctor` reports which transcriber will run, and flags a forced
  driver that is not configured — including `provider` on an LLM that cannot
  hear.
- Transcription failures map to real codes: a throttled service is
  `rate_limited` (429, retryable), a rejected key says so rather than blaming
  the recording, and a `base_url` ending in `/audio/transcriptions` names that
  specific mistake.

### Fixed
- `ssl_verify` now applies to every outbound call the package makes, not only
  LLM ones. A transcription endpoint that ignored it would fail at the
  handshake while the LLM calls worked — a bewildering thing to debug on a
  stack whose PHP has no CA store.

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
  with no publish step. Browser speech-to-text on every provider with a `/voice`
  fallback, text-to-speech, bar/table/card rendering, clarification prompts and
  multi-turn follow-ups.
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
