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
  range, a lint job, and integration jobs that execute the real generated SQL
  against PostgreSQL 16 and 18, MySQL 8.4 and 9, and MariaDB 11. Those jobs
  fail if their tests skip rather than run, because a skipped test is not a
  passing test.

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
