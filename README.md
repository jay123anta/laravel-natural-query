# Laravel NaturalQuery

Privacy-safe natural language to SQL engine for Laravel. AI sees only your schema structure, never your actual data.

Ask questions in plain English. Get SQL results. Data never leaves your server.

```php
$result = NaturalQuery::query("top 5 customers by revenue");
$result = NaturalQuery::query("total units held per warehouse bin");
$result = NaturalQuery::query("which districts have the most pending applications");
```

It holds no knowledge of your domain. `naturalquery:discover` reads *your*
database and writes a config file per table; those files are the only thing
that makes it understand your data, so any schema works without code changes.

## Requirements

- PHP 8.2 – 8.5
- Laravel 12 or 13. Laravel 10 and 11 are not supported: both are past
  security support, so every published version carries advisories and
  Composer declines to install them by default.
- PostgreSQL or MySQL/MariaDB - **not SQLite**, which Laravel 11+ uses by
  default (the engine introspects your schema). See
  [Troubleshooting](#troubleshooting) for the two-line fix, or register your
  own introspector under `sql.introspectors`.
- One LLM provider - your choice of model, hosted or self-hosted:
  - Built-in drivers: Gemini, OpenAI, Claude, Ollama (local)
  - Any OpenAI-compatible service: DeepSeek, Groq, Mistral, OpenRouter,
    vLLM, LM Studio, LocalAI, llama.cpp server, …

## Installation

```bash
composer require jayanta/laravel-natural-query
php artisan naturalquery:install
php artisan migrate
```

Add your LLM key to `.env`:

```env
NATURALQUERY_LLM_DRIVER=gemini
GEMINI_API_KEY=your-key-here
```

Supported drivers: `gemini`, `openai`, `claude`, `ollama`

```env
# OpenAI
NATURALQUERY_LLM_DRIVER=openai
OPENAI_API_KEY=sk-...

# Claude
NATURALQUERY_LLM_DRIVER=claude
ANTHROPIC_API_KEY=sk-ant-...

# Ollama (local, no API key needed)
NATURALQUERY_LLM_DRIVER=ollama
OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=llama3
```

### Bring your own model - hosted or self-hosted

No model is fixed by this package. Every provider's model is set by you, and
**any service speaking the OpenAI chat-completions protocol plugs in without
code changes** - that covers DeepSeek, Groq, Mistral, Together, OpenRouter,
and self-hosted stacks like vLLM, LM Studio, LocalAI, and llama.cpp server.

```env
# DeepSeek (config block ships ready-made)
NATURALQUERY_LLM_DRIVER=deepseek
DEEPSEEK_API_KEY=sk-...
DEEPSEEK_MODEL=deepseek-chat

# Self-hosted (vLLM / LM Studio / LocalAI / llama.cpp - usually keyless)
NATURALQUERY_LLM_DRIVER=selfhosted
SELFHOSTED_LLM_URL=http://localhost:8000/v1
SELFHOSTED_LLM_MODEL=qwen2.5-coder:14b
```

For anything else, add your own block under `llm.providers` in
`config/naturalquery.php` with a `base_url` + `model`, then set
`NATURALQUERY_LLM_DRIVER=<your-block-name>`. Tip for self-hosted servers that
reject OpenAI's `response_format` parameter: set `force_json => false` in the
block. Fully air-gapped deployments (government, healthcare) can pair a
self-hosted model with the privacy wall - nothing, not even schema, leaves
your network.

## Check your setup before anything else

```bash
php artisan naturalquery:doctor
```

New to this? Run that first, and run it again any time something misbehaves.
It checks your driver and API key, pings the provider to confirm the model is
actually live, verifies the database connection and migrations, and - the one
that catches most silent failures - confirms every table and column named in
your schema files really exists in your database. Each problem is printed with
the exact command or setting that fixes it. It never prints your API key, and
`--skip-api` runs the whole checkup without spending any quota.

```
  Schemas
    ✓ 2 schema(s) loaded: orders, customers
    ✗ Schema 'orders': column(s) not in 'demo_orders': revenu
      → Fix: Correct these names in the schema file - the AI is told they
        exist, so queries using them will fail at execution.
```

## Quick Start

### 1. Create a schema file

```bash
php artisan naturalquery:discover --ai
```

This is the whole adaptation step. The package holds no knowledge of your
application: it reads *your* database, writes one plain PHP config file per
table into `config/naturalquery-schemas/`, and from then on those files are
the only thing that makes it "know" your domain. No code changes, no
subclassing, no service registration - any Laravel app is supported by
generating (or hand-writing) these files.

Without `--ai` you get the structural layer filled in for you: table and
column names, types, and a first guess at which columns are dimensions
(groupable/filterable) versus measures (`aggregatable`, so totals SUM per
group). With `--ai` your configured model also fills in the *human* layer that
otherwise has to be written by hand - dataset name, description, the aliases
people actually say, business rules as `llm_instructions`, `computed_metrics`
for rates and averages, and worked `example_queries`.

Useful flags:

```bash
php artisan naturalquery:discover --dry-run          # preview, write nothing
php artisan naturalquery:discover --table=orders     # just one table
php artisan naturalquery:discover --all-tables       # include framework tables
php artisan naturalquery:discover --ai --no-verify   # skip EXPLAIN checking
php artisan naturalquery:discover --merge            # update after a migration, keep your edits
php artisan naturalquery:discover --force            # regenerate, DISCARDING your edits
```

### Keeping schema files current after a migration

A schema file has two layers. The **structural** layer — which columns exist
and their types — comes from your database. The **human** layer — descriptions,
aliases, `llm_instructions`, `computed_metrics`, `example_queries`, and the
judgement about which column is a measure — is what actually makes the dataset
usable, and nothing can recover it from the database.

So when a migration changes a table, use `--merge`:

```bash
php artisan naturalquery:discover --merge --table=orders
```

It refreshes the structural layer around your curation:

```
  [table] public.orders
    Merged — your descriptions, aliases, instructions, metrics and examples kept
      + new column(s): region
      - column(s) gone from the database: legacy_ref
```

Framework plumbing (`migrations`, `jobs`, `sessions`, `cache`,
`telescope_*`, …) is skipped by default so your real tables aren't buried;
the skip list lives in `schema.discover_exclude`.

**What the generator guarantees.** Two failures here are silent and
expensive, so both are checked rather than assumed:

- *Every generated file is verified to parse and return an array* before it is
  reported as created. Schema files load on every request, so one bad file -
  say a column comment containing an apostrophe - would otherwise take the
  application down after a run that looked successful.
- *Every AI-suggested example query is validated and planned against your real
  database* with `EXPLAIN`. Examples are fed back to the AI as few-shot
  guidance, so an invented column in an example would quietly teach the model
  that the column exists and corrupt later queries. Anything that isn't a
  SELECT, touches another table, or doesn't plan cleanly is dropped with a
  note. `EXPLAIN` plans without executing, so no data is read - the privacy
  wall holds during discovery too.

Generated files are yours to edit; they are ordinary config. Re-run with
`--force` to regenerate, and `php artisan naturalquery:doctor` afterwards to
confirm everything still lines up with the database.

Or create one manually:

```php
// config/naturalquery-schemas/orders.php
return [
    'name' => 'Orders',
    'description' => 'Customer orders with products and revenue',
    'aliases' => ['orders', 'sales', 'revenue'],
    'connection' => null, // uses default

    'llm_instructions' => "
        This table has customer orders.
        GROUP BY customer_name for customer analysis.
        GROUP BY region for geographical analysis.
        revenue = quantity * unit_price
    ",

    'tables' => [
        'primary' => [
            'name' => 'public.orders',
            'group_column' => 'customer_name',
            'columns' => [
                'customer_name' => [
                    'type' => 'varchar',
                    'description' => 'Customer name',
                    'filterable' => true,
                    'groupable' => true,
                ],
                'quantity' => [
                    'type' => 'integer',
                    'description' => 'Items ordered',
                    'aliases' => ['items', 'count', 'units'],
                    'aggregatable' => true,
                    'sortable' => true,
                ],
                'revenue' => [
                    'type' => 'decimal',
                    'description' => 'Order revenue',
                    'unit' => '$',
                    'aliases' => ['sales', 'amount', 'total'],
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],

    'example_queries' => [
        ['natural' => 'Top 10 customers by revenue', 'sql' => 'SELECT customer_name, SUM(revenue) AS total FROM public.orders GROUP BY customer_name ORDER BY total DESC LIMIT 10'],
        ['natural' => 'Total revenue', 'sql' => 'SELECT SUM(revenue) AS total_revenue FROM public.orders'],
    ],

    'max_limit' => null,
    'default_metric' => 'revenue',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
```

### 2. Query

```php
use Jayanta\NaturalQuery\Facades\NaturalQuery;

// Basic query
$result = NaturalQuery::query("top 10 customers by revenue");

// With scheme hint (skips scheme detection)
$result = NaturalQuery::query("top 10 by revenue", "orders");

// Result structure
[
    'status' => 'success',
    'rows' => [...],
    'answer' => 'Top 10 by Order revenue (highest): Customer A, Customer B...',
    'speech_text' => 'Here are the 10 entries with the highest...',
    'insights' => ['total' => '1,234,567', 'average' => '123,456'],
    'visualization' => 'bar',
    'metadata' => ['processing_time_ms' => 2500, 'cache_hit' => false],
]
```

### 3. API Endpoints

Routes are registered at `/naturalquery/*` by default:

```
POST /naturalquery/text              → Text query
POST /naturalquery/voice             → Voice query (Gemini only)
POST /naturalquery/conversation      → Multi-turn conversation
GET  /naturalquery/health            → Health check
GET  /naturalquery/schemes           → Available schemas
POST /naturalquery/feedback          → Submit correction
GET  /naturalquery/feedback/stats    → Feedback statistics
GET  /naturalquery/cache-stats       → Cache statistics
POST /naturalquery/clear-cache       → Clear cache
```

Example API call:

```bash
curl -X POST http://localhost/naturalquery/text \
  -H "Content-Type: application/json" \
  -d '{"text": "top 5 customers by revenue"}'
```

## Drop-in Widget (text + voice UI)

Don't want to build a frontend? The package ships one. Add this to any Blade view:

```blade
<x-naturalquery::widget />
```

That single line renders a complete assistant: text input, microphone, spoken
answers, result cards / bar charts / tables, clarification buttons, and
follow-up questions ("what about the South region?") via the conversation API.

- **Voice works with every LLM provider** - speech-to-text happens in the
  browser (Chrome/Edge/Safari). Where browser STT is unavailable, the widget
  falls back to recording audio and sending it to `/voice` (needs a
  voice-capable provider such as Gemini). Text input always works.
- **No build step, no publishing** - the widget JS is served by the package at
  `/naturalquery/widget.js`. To bundle it yourself instead:
  `php artisan vendor:publish --tag=naturalquery-assets`
- **Customizable per instance:**

```blade
<x-naturalquery::widget
    title="Ask about sales"
    scheme="orders"
    language="en-IN"
    :examples="['Top 10 customers by revenue', 'Revenue by region']"
/>
```

Site-wide defaults live in `config/naturalquery.php` under `widget`
(title, language, theme color, example chips, TTS on/off, conversation mode).

A ready-made demo page is available at `/naturalquery/demo` (enabled in the
`local` environment by default; control with `NATURALQUERY_DEMO_PAGE`).

Using your own frontend instead? Everything the widget does goes through the
public REST endpoints listed above - the widget is optional sugar.

## Configuration

Publish the config:

```bash
php artisan vendor:publish --tag=naturalquery-config
```

### Query Modes

```php
// config/naturalquery.php
'query_mode' => 'auto', // recommended
```

| Mode | How it works |
|---|---|
| `intent` | AI extracts intent, local SQL builder constructs query. Safest. |
| `sql_generation` | AI generates SQL directly from schema context. Most flexible. |
| `auto` | Tries intent first, falls back to sql_generation. Recommended. |

### Single-Schema Projects

If your project has only one dataset:

```env
NATURALQUERY_DEFAULT_SCHEME=orders
```

All queries go to that schema. No scheme detection needed.

### Multi-Schema Projects

For projects with multiple datasets, configure query routing:

```php
// config/naturalquery.php
'query_routing' => [
    'toilet' => 'sbmu',
    'housing' => 'pmayg',
    'revenue' => 'sales',
],

'system_instructions' => "
    This is a dashboard with 3 datasets.
    Toilet/sanitation queries use the sbmu dataset.
    Housing queries use pmayg.
    Sales/revenue queries use the sales dataset.
",
```

## Schema Config Reference

Each schema file in `config/naturalquery-schemas/` tells the AI everything about one dataset.

### Columns

```php
'columns' => [
    'column_name' => [
        'type' => 'varchar|integer|decimal|date|boolean',
        'description' => 'What this column contains',
        'unit' => '$',                    // display unit
        'aliases' => ['revenue', 'sales'], // words users might say
        'filterable' => true,             // can be used in WHERE
        'groupable' => true,              // can be used in GROUP BY
        'aggregatable' => true,           // summed per group (see below)
        'sortable' => true,               // can be used in ORDER BY
    ],
],
```

**`aggregatable` drives aggregation.** For transactional tables (many rows
per group value - e.g. one row per order), mark measure columns
`'aggregatable' => true`: rankings and group detail views then build
`SUM(column) ... GROUP BY group_column` so each group appears once with its
total. For pre-aggregated tables (one row per group value - e.g. one row per
district with pre-computed totals), omit `aggregatable` (use `sortable`
alone) and rows are read as-is with no GROUP BY. Computed metrics whose
expression already aggregates (`SUM`/`COUNT`/`AVG`/`MIN`/`MAX`) are grouped
as-is and never double-wrapped.

### Computed Metrics

For calculated values that don't exist as columns:

```php
'computed_metrics' => [
    'completion_rate' => [
        'expression' => 'ROUND(completed * 100.0 / NULLIF(target, 0), 2)',
        'description' => 'Completion percentage',
        'unit' => '%',
        'aliases' => ['rate', 'percentage', 'progress'],
    ],
],
```

### JOINs

When your data table uses IDs instead of names:

```php
'tables' => [
    'primary' => [
        'name' => 'data_table',
        'group_column' => 'district_name',
        'required_join' => 'JOIN districts d ON d.id = data_table.district_id',
        'select_override' => 'd.district_name',
        'columns' => [...],
    ],
],
```

### LLM Instructions

The most important field for accuracy. Plain English instructions for the AI:

```php
'llm_instructions' => "
    This data has 4 components with DIFFERENT tables:
    1. Waste data → waste_table (JOIN districts for names)
    2. Ward data → ward_table (has district_name directly)
    3. Toilet data → toilet_table

    Always exclude type='cnd' for waste queries.
    Use ROUND(value::numeric, 2) for PostgreSQL decimals.
    'Constructed' means project_status = 'Constructed'.
",
```

### Example Queries

More examples = fewer AI errors. Cover every query pattern:

```php
'example_queries' => [
    // Ranking
    ['natural' => 'Top 10 by revenue', 'sql' => 'SELECT ... ORDER BY revenue DESC LIMIT 10'],
    // Bottom/worst
    ['natural' => 'Worst 5 performers', 'sql' => 'SELECT ... ORDER BY rate ASC LIMIT 5'],
    // Single record
    ['natural' => 'Show details for Tokyo', 'sql' => "SELECT ... WHERE LOWER(name) = LOWER('Tokyo') LIMIT 1"],
    // Aggregation
    ['natural' => 'Total revenue', 'sql' => 'SELECT SUM(revenue) FROM ...'],
    // Computed metric
    ['natural' => 'Best completion rate', 'sql' => 'SELECT ..., ROUND(...) AS rate ... ORDER BY rate DESC'],
    // If JOIN needed - show it in EVERY example
    ['natural' => 'Districts by count', 'sql' => 'SELECT d.name, COUNT(*) FROM data JOIN districts d ON ... GROUP BY d.name'],
],
```

## Multi-Turn Conversation

```php
use Jayanta\NaturalQuery\Conversation\ConversationManager;

$conv = app(ConversationManager::class);

// Turn 1
$r1 = $conv->query('session-123', 'top 5 by pending in basundhara');
// Turn 2 - inherits scheme context
$r2 = $conv->query('session-123', 'filter by Kamrup');
// Turn 3 - inherits scheme + district
$r3 = $conv->query('session-123', 'compare with Nagaon');
```

## Feedback / Training

Users can submit corrections. These are fed into future prompts so the AI learns:

```bash
curl -X POST /naturalquery/feedback \
  -d '{"query":"show performance","scheme":"sbmu","correction":"Should use performance column, not pending","feedback_type":"wrong_metric"}'
```

## Artisan Commands

```bash
php artisan naturalquery:install          # Setup package
php artisan naturalquery:doctor           # Diagnose setup problems + print fixes
php artisan naturalquery:doctor --skip-api  # ...without calling the AI provider
php artisan naturalquery:discover         # Auto-generate schema files from DB
php artisan naturalquery:discover --ai    # ...plus descriptions, metrics, examples
php artisan naturalquery:discover --dry-run  # Preview without writing files
php artisan naturalquery:cache-cleanup    # Clean stale cache entries
php artisan naturalquery:debug "query"    # Show exact prompt sent to AI
```

`naturalquery:doctor` exits non-zero when it finds a real problem, so it works
as a deployment smoke test:

```bash
php artisan naturalquery:doctor --skip-api || exit 1
```

## Security

Three layers of defense:

**Layer 1: Input Guard** - blocks prompt injection, SQL injection in text, data exfiltration attempts, unicode bypass attacks before they reach the AI.

**Layer 2: AI Prompt** - schema structure only (never data). Forced JSON output. Instructions say "ONLY SELECT queries".

**Layer 3: SQL Validator** - validates AI-generated SQL. Forbidden keywords (DROP, DELETE, etc), table whitelist (every FROM/JOIN table checked), injection patterns, LIMIT enforcement, no stacked queries, no PL/pgSQL.

**Optional Layer 4: AI Guard** - install [jayanta/laravel-ai-guard](https://github.com/jay123anta/laravel-ai-guard) for additional protection. NaturalQuery auto-detects it - no configuration needed.

```bash
composer require jayanta/laravel-ai-guard
```

When installed, ai-guard adds:
- 30 prompt injection patterns (instruction overrides, jailbreaks, DAN attacks, token manipulation) with confidence scoring
- 354 bot signatures to block AI scrapers and malicious bots at the middleware level
- Honeypot trap routes, PII leak detection on responses, and optional ML-based detection

**ai-guard's own settings decide what it blocks here.** Every question is run
through its detector, but a detection is only refused when ai-guard is in
`block` mode and scores at or above its `confidence_threshold` (default 70).
Its shipped default mode is `log_only`, so installing the package gives you
visibility first - it will not start rejecting questions your users could ask
yesterday. Detections that don't block are logged at info level, so nothing is
silently dropped. Layers 1–3 apply either way.

To act on detections, switch ai-guard itself to blocking:

```php
// config/ai-guard.php
'mode' => 'block',
'confidence_threshold' => 70,
```

Or override the decision from NaturalQuery's side:

```env
NATURALQUERY_AI_GUARD_ENFORCE=always   # block above threshold in any ai-guard mode
NATURALQUERY_AI_GUARD=false            # ignore ai-guard even though it's installed
```

Neither package requires the other. They work independently and integrate automatically when both are present.

## Privacy

- AI receives ONLY table names, column names, and types
- Your actual data NEVER leaves your server
- AI generates SQL which runs locally on YOUR database
- Error messages are sanitized (no API keys, no internal paths); logged
  exceptions have API keys and tokens redacted

This is enforced by the test suite, not just by intent. `PrivacyWallTest`
seeds a database with sentinel values, runs real queries end to end through a
recording provider, and asserts those values appear nowhere in anything sent
upstream - across intent mode, SQL-generation mode, self-verification, the
retry path, multi-turn follow-ups, clarifications, and voice. Every case also
asserts the query genuinely returned sentinel-bearing rows, so a query that
quietly failed cannot pass by transmitting nothing.

Run it yourself:

```bash
vendor/bin/phpunit --filter PrivacyWallTest
```

## Troubleshooting

**`cURL error 60: SSL certificate problem` on Windows / XAMPP / WAMP** - your
PHP has no CA certificate store, so HTTPS calls to the LLM provider can't be
verified. Download [cacert.pem](https://curl.se/ca/cacert.pem) and point the
package at it:

```env
NATURALQUERY_SSL_VERIFY=C:\path\to\cacert.pem
```

(Or fix it globally by setting `curl.cainfo` in `php.ini` to the same file.)
`ssl_verify` accepts `true` (default - system CA bundle), a CA bundle file
path, or `false` (disables verification - never do this in production).

**`Driver 'sqlite' is connected but NaturalQuery cannot introspect it`** -
Laravel 11 and 12 ship with `DB_CONNECTION=sqlite`, and NaturalQuery reads your
schema through database introspection, which it supports on PostgreSQL and
MySQL/MariaDB only. Either move the app to a supported driver:

```env
DB_CONNECTION=mysql
```

…or keep the app on SQLite and point only NaturalQuery elsewhere:

```php
// config/naturalquery.php
'sql' => [
    'database_connection' => 'mysql',   // a connection from config/database.php
],
```

Adding another database is a config change, not a fork - implement
`Contracts\SchemaIntrospectorInterface` and register it under
`sql.introspectors`.

**404 from the LLM provider** - the configured model may have been retired.
Check the provider's live model list and update e.g. `GEMINI_MODEL`, then run
`php artisan config:clear`.

**"The AI service is receiving too many requests right now (rate limit)"** -
the provider returned HTTP 429. Free-tier API keys (especially Gemini) have
low per-minute and per-day quotas; wait a minute and retry, enable the query
cache (`NATURALQUERY_CACHE_ENABLED=true`) so repeated questions skip the API
entirely, or upgrade the key's quota. The package fails fast on 429 - it
deliberately does not fall back or retry the whole query, since more calls
would extend the rate-limit window.

**Slow responses when the provider is struggling** - network-level retries
are bounded by the `retry` config block, which exists because waiting is
blocking and PHP-FPM workers are a finite pool:

```php
'retry' => [
    'base_delay_ms' => 250,       // first wait; doubles each attempt
    'max_delay_ms' => 2000,       // ceiling for any single wait
    'total_budget_ms' => 4000,    // ceiling for ALL waits in one API call
    'respect_retry_after' => true,
    'jitter' => true,             // de-synchronise concurrent workers
],
```

With the defaults, one API call waits well under a second in total. When the
next wait would blow the budget - including an oversized `Retry-After: 60`
hint - the call fails immediately rather than half-waiting. Transient 5xx
responses get the same bounded retry; other 4xx (bad key, malformed request)
are never retried, since they fail identically every time. On queue workers
or CLI, where blocking is cheap, raising `total_budget_ms` is reasonable; on
FPM a large budget risks tying up every worker during a provider outage.

## License

MIT
