# Changelog

All notable changes to `jayanta/laravel-natural-query` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  range, a lint job, and a PostgreSQL integration job running against
  PostgreSQL 16 and 18.

  Laravel 10 and 11 are intentionally not supported. Both are past security
  support, so every published version carries advisories and Composer refuses
  to install them under its default `policy.advisories.block`. A version the
  package manager will not install is not a version worth claiming.
- Pluggable schema introspection: `pgsql`, `mysql` and `mariadb` are built in,
  and any other database can be supported by implementing
  `Contracts\SchemaIntrospectorInterface` and registering it under
  `sql.introspectors`. The built-in map lives in code, not in the publishable
  config, so apps that published their config under an earlier version keep
  working across upgrades — Laravel merges package config only one level deep.

### Fixed
- **Schema discovery crashed on any PostgreSQL database with the same table
  name in two schemas.** `listTables()` estimated row counts with a subquery
  matching `pg_class.relname` alone, but `pg_class` is database-wide, so a
  second matching row raised `more than one row returned by a subquery used as
  an expression` and `naturalquery:discover` died. One schema per subject area
  is an ordinary Postgres layout. The subquery now joins `pg_namespace` and
  matches on schema as well as name.
- **Table and column comments were unreadable for mixed-case or
  reserved-word names.** `obj_description()` and `col_description()` were fed
  raw concatenated identifiers, so the `::regclass` cast threw
  `relation does not exist` and aborted the listing. Identifiers are now
  quoted with `format('%I.%I', …)`.
- `{prefix}/widget.js` returned 500 on any database driver the package cannot
  introspect. The route was handled by the controller that pulls in the whole
  engine, so serving a static JavaScript file required a supported database and
  every page embedding `<x-naturalquery::widget />` broke. Laravel 11+ defaults
  to SQLite, so this was the out-of-the-box experience for many new apps. The
  widget asset and the demo page now live in a dependency-free controller.
- `naturalquery:doctor` reported "✓ Connected (sqlite …)" and exited 0 on a
  setup where every query and every route would fail. It now checks that the
  driver is introspectable, checks the connection NaturalQuery is actually
  configured to use rather than the app default, and prints the exact fix.
- The unsupported-driver exception now names the driver, the supported list,
  the SQLite-specific fix, and points at `naturalquery:doctor`.
- `ResponseFormatter::formatClarification` read intent keys unguarded, so a
  provider returning only the keys it resolved produced an undefined-key error
  that surfaced as a generic failure instead of a clarification.

### Security
- `guzzlehttp/guzzle` is floored at `^7.15.1`. The package makes authenticated
  outbound HTTP on every query, and earlier 7.x releases carry open advisories.

[Unreleased]: https://github.com/jay123anta/laravel-natural-query/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/jay123anta/laravel-natural-query/releases/tag/v1.0.0
