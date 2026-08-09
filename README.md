# Laravel NaturalQuery

**Let people ask your database questions in English — without your data ever
leaving your server.**

[![Tests](https://github.com/jay123anta/laravel-natural-query/actions/workflows/tests.yml/badge.svg)](https://github.com/jay123anta/laravel-natural-query/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

<!-- Add once published to Packagist — until then these render as "not found":
[![Packagist](https://img.shields.io/packagist/v/jayanta/laravel-natural-query.svg)](https://packagist.org/packages/jayanta/laravel-natural-query)
[![Downloads](https://img.shields.io/packagist/dt/jayanta/laravel-natural-query.svg)](https://packagist.org/packages/jayanta/laravel-natural-query)
-->

<!-- TODO before release: replace with a ~10s screen recording of the widget
     answering a question, then rendering the chart. Nobody adopts what they
     cannot see working. -->

The AI is sent your **schema structure only** — table names, column names,
types, and the words your users use for them. It returns SQL. Your server
validates that SQL, runs it locally, and formats the rows. **Not one row is
ever sent upstream**, and that is enforced by tests, not by intent.

Pair it with a self-hosted model (Ollama, vLLM, LM Studio, llama.cpp) and
**nothing leaves your network at all** — not even the schema. That is the case
hosted BI tools cannot serve: government, healthcare, finance, anywhere the
data genuinely cannot go to a third party.

```php
$result = NaturalQuery::query("top 5 customers by revenue");
$result = NaturalQuery::query("total units held per warehouse bin");
$result = NaturalQuery::query("which districts have the most pending applications");
```

Or drop the whole UI — text box, microphone, charts — into any Blade view with
one line:

```blade
<x-naturalquery::widget />
```

## What it is good at, and what it is not

Worth being straight about this, because natural-language-to-SQL is often
oversold.

**It works well** on datasets you have described — which is what
`naturalquery:discover --ai` sets up for you, and what you then refine. Told
that `revenue` is a measure to total, that users say "client" for
`customer_name`, and that cancelled orders don't count, it is reliable for the
questions those datasets are meant to answer.

**It is not magic.** Pointed at an undescribed database and asked something
vague, any text-to-SQL system — this one included — will sometimes produce a
confident, wrong answer. The mitigations here are real: generated SQL is
SELECT-only and restricted to your tables, `naturalquery:doctor` catches
schema drift before your users do, corrections you submit feed back into later
prompts, and every answer is rendered with its numbers so they can be checked.
But the honest framing is **a fast analyst for datasets you have curated**, not
an oracle for arbitrary databases.

**Measured, not asserted.** Against a sample of the Spider text-to-SQL
benchmark — real questions, two unfamiliar databases, no curation of any kind,
scored by comparing result sets to the gold SQL — it answers **29 of 36 (81%)**.
On a fourteen-table shop schema it scores 79% out of the box and 86% with three
sentences of `system_instructions`. Reproduce both with
`vendor/bin/phpunit --testsuite Benchmark` and your own key.

Read that as: roughly one question in five is wrong on an *uncurated* schema.
That is why every answer shows the measure, breakdown and filters it used —
a misreading you can see is a different thing from one you cannot.

**Every provider is checked against the same battery.** Twelve cases with
answers you can verify by hand on three rows of seeded data — totals, counts,
filters, averages, rankings, and a conversation that narrows, drills down and
rewinds:

```bash
NATURALQUERY_CONFORMANCE=1 \
NATURALQUERY_LLM_DRIVER=claude \
NATURALQUERY_CONFORMANCE_KEY=sk-... \
vendor/bin/phpunit --testsuite Conformance
```

| Provider | Model | Result |
|---|---|---|
| Gemini | gemini-2.5-flash | 12/12 |
| Claude | claude-sonnet-5 | 12/12 |
| DeepSeek | deepseek-v4-flash | 12/12 |
| Mistral | mistral-large-latest | 12/12 |
| Groq | llama-3.3-70b-versatile | 12/12 |
| Groq | llama-3.1-8b-instant | 11/12 |

**On model size.** The 70B open-weight model passes everything, which is the
result that matters if you self-host — that class runs on one good GPU. The 8B
missed one case: it did not pick "in Guwahati" out of *"total amount in
Guwahati"* and answered for every city. Nothing else differed. **Use a
70B-class model or better for anything where a wrong number matters**, and if
you must run something smaller, describe your columns well — the aliases and
descriptions in your schema files are what a small model leans on hardest.

It exists because unit tests could not find what it finds. A canned response
happily answers a request the real API would reject, so Claude's driver was
broken on every call and nothing failed; and a filter dropped in the engine
only showed up on a two-turn chain. Add `NATURALQUERY_CONFORMANCE_DELAY=20`
for a free tier.

It ships knowing nothing about your domain. `naturalquery:discover` reads
*your* database and writes a config file per table; those files are the only
thing that makes it understand your data, so any schema works without code
changes.

## How it works

```
  your question ─┐
                 ├─► LLM ─► SQL ─► validated ─► runs on YOUR database ─► rows ─► answer
  your schema ───┘                 SELECT-only,
   (structure only)                your tables only          rows never go back to the LLM
```

1. **The question is screened** for prompt injection, SQL hidden in text, and
   exfiltration attempts — before anything leaves your server.
2. **A prompt is built** from your schema config plus the question. The model
   sees table names, column names, types, descriptions and the words your users
   use for them. It never sees a single row.
3. **The model replies** with either a small structured intent — from which
   *your server* builds the SQL — or with SQL directly.
4. **The SQL is validated**: SELECT-only, and every table in it must be one of
   yours. Anything else is refused.
5. **It runs on your database**, on your server. The rows are turned into an
   answer, a chart hint and a spoken sentence — and are never sent upstream.

The model is a translator between English and your schema. It never sees your
data and never touches your database.

**The schema config files are the whole knowledge layer.** They are ordinary
PHP config, generated by `discover` and yours to edit: add the words your users
actually say as `aliases`, business rules as `llm_instructions`, calculations as
`computed_metrics`, worked examples as `example_queries`. When it gets something
wrong you edit config, not code.

## Requirements

- PHP 8.2 – 8.5
- Laravel 12 or 13. Laravel 10 and 11 are not supported: both are past
  security support, so every published version carries advisories and
  Composer declines to install them by default.
- SQLite, PostgreSQL, or MySQL/MariaDB. SQLite is what `laravel new` gives
  you, so a stock Laravel app needs no database change to try this. Anything
  else is a config change rather than a fork - implement
  `Contracts\SchemaIntrospectorInterface` and register it under
  `sql.introspectors`.
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

Then choose a model in `.env`. **A model you run yourself is a first-class
choice, not a fallback** - the package works the same either way, and nothing
here assumes a hosted API.

```env
# Local, no API key, nothing leaves your machine
NATURALQUERY_LLM_DRIVER=ollama
OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=llama3
```

```env
# Or a hosted API
NATURALQUERY_LLM_DRIVER=gemini
GEMINI_API_KEY=your-key-here

NATURALQUERY_LLM_DRIVER=openai
OPENAI_API_KEY=sk-...

NATURALQUERY_LLM_DRIVER=claude
ANTHROPIC_API_KEY=sk-ant-...
```

Built-in drivers: `ollama`, `gemini`, `openai`, `claude` - plus any
OpenAI-compatible service, below.

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
`NATURALQUERY_LLM_DRIVER=<your-block-name>`. That is the whole procedure —
here is Mistral, added exactly that way and verified end to end:

```php
// config/naturalquery.php → llm.providers
'mistral' => [
    'api_key'  => env('MISTRAL_API_KEY'),
    'model'    => env('MISTRAL_MODEL', 'mistral-large-latest'),
    'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
    'force_json' => true,
],
```

```env
NATURALQUERY_LLM_DRIVER=mistral
MISTRAL_API_KEY=...
```

`naturalquery:doctor` then confirms the driver, the key and that the model is
live — and tells you precisely what is missing if the block is wrong.

Tip for self-hosted servers that reject OpenAI's `response_format` parameter:
set `force_json => false` in the block. Fully air-gapped deployments (government, healthcare) can pair a
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

**That difference is not cosmetic, and averages are the clearest case.** A
plain-discovered schema has no `computed_metrics`, and intent mode can only
SUM a measure — so "average amount" is a question it cannot express. It is
routed to SQL generation instead, which writes the `AVG()` and answers
correctly, at the cost of one extra API call and the determinism intent mode
gives you. Declare the ones your users ask for and they are answered directly:

```php
'computed_metrics' => [
    'avg_order_value' => [
        'expression' => 'ROUND(AVG(amount), 2)',
        'aliases' => ['average order value', 'average'],
    ],
],
```

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

That single line renders a complete assistant: a chat frame with the thread
above and the composer below, microphone and spoken answers, result cards /
bar charts / tables, clarification buttons, and follow-up questions ("what
about the South region?") via the conversation API.

- **It is laid out as a conversation, deliberately.** Questions on the right,
  answers on the left, composer pinned at the bottom — the shape of every
  messaging app. A search box above a result reads as one question at a time,
  and people did not try follow-ups because nothing suggested they could.
- **Voice works with every LLM provider** - speech-to-text happens in the
  browser (Chrome/Edge/Safari), so no audio leaves the user's machine, nothing
  needs configuring, and the model only ever reads English text. Firefox has no
  speech API, so the microphone is hidden there. Text input always works. See
  [Voice](#voice-the-browser-listens-the-server-reads-text).
- **No build step, no publishing** - the widget JS is served by the package at
  `/naturalquery/widget.js`. To bundle it yourself instead:
  `php artisan vendor:publish --tag=naturalquery-assets`
- **Customizable per instance:**

```blade
<x-naturalquery::widget
    title="Ask about sales"
    scheme="orders"
    language="en-IN"
    height="640px"
    :examples="['Top 10 customers by revenue', 'Revenue by region']"
/>
```

`height` sets the chat frame; the thread scrolls inside it and the composer
stays put. Pass `height="auto"` to let the widget grow with its content
instead, which suits a short embed in a page that scrolls as a whole.

Example queries appear while the thread is empty and clear once it starts —
knowing what can be asked is the hard part, and an empty prompt gives no clue.

Site-wide defaults live in `config/naturalquery.php` under `widget`
(title, language, theme color, frame height, example chips, TTS on/off,
conversation mode).

A ready-made demo page is available at `/naturalquery/demo` (enabled in the
`local` environment by default; control with `NATURALQUERY_DEMO_PAGE`).

## Voice: the browser listens, the server reads text

**There is nothing to configure, and no audio endpoint.**

The widget uses the browser's `SpeechRecognition` to turn speech into English
text on the device, then posts that text to `/text` exactly as if it had been
typed. That is the entire voice design, and it buys three things at once:

- **It works with every LLM** — Gemini, Claude, OpenAI, Ollama, anything
  self-hosted — because by the time the model is involved it is reading a
  sentence, not hearing a recording.
- **No audio ever leaves the user's machine.** Not to your server, not to a
  model provider. There is no upload path in the package at all.
- **Nothing to set up and nothing extra to pay for** — no transcription
  service, no second API key, no added latency.

Chrome, Edge and Safari support it. Firefox does not, so the widget hides the
microphone there and people type — which is why text input is never optional.

### English only, on purpose

`language` chooses which **English accent** the browser listens for and speaks
back — `en-US`, `en-GB`, `en-IN`, `en-AU`. It matters: `en-IN` recognises
Indian English far more accurately than `en-US` does.

```blade
<x-naturalquery::widget language="en-IN" />
```

Another locale may appear to work, because the browser will attempt the
recognition, but nothing downstream is built for it — the prompts, the schema
descriptions and the generated answers are all English. **Multilingual is a
separate package** with a speech pipeline of its own. This one stays an
English natural-language-to-SQL assistant.

## Who is allowed to ask

It works the moment you install it, and it is not open in production.

| | Who gets in |
|---|---|
| A `viewNaturalQuery` gate you define | Whatever the gate says — always |
| No gate, `local` or `testing` | Everyone, so you can evaluate the package without building a login first |
| No gate, anywhere else | Signed-in users only |

```php
// AppServiceProvider::boot() — define this as soon as it is more than you
Gate::define('viewNaturalQuery', fn ($user) => $user->isAdmin());
```

**These endpoints spend money on every request**, so an ungated one in
production is an LLM proxy for the internet, and `limits.queries_per_day` is
then counted per IP — easy to get around.

The check is applied by the package regardless of what is in
`routes.middleware`, because emptying that list is the first thing people do to
make the widget public, and it should not silently remove authorization too.
Refusals are **403**, never a redirect.

> `auth` used to be the default middleware. On a fresh Laravel app — which has
> no login route — that made the first thing a new adopter saw
> `RouteNotFoundException: Route [login] not defined`, a 500 on the demo page
> the README sends them to. Add `auth` back if your app has auth scaffolding
> and you want a redirect instead of a 403.

## Events and cost

An AI feature spends money on every request and can be confidently wrong, so an
application needs to see what its own feature is doing. Four events give it
somewhere to stand — listen to none and nothing changes.

```php
use Jayanta\NaturalQuery\Events\QuestionAnswered;

Event::listen(QuestionAnswered::class, function ($e) {
    AiUsage::create([
        'user_id'  => auth()->id(),
        'question' => $e->question,
        'sql'      => $e->sql,          // server-side only; never in the HTTP response
        'rows'     => $e->rowCount,
        'ms'       => $e->durationMs,
        'tokens'   => $e->usage['total_tokens'] ?? null,
        'cached'   => $e->cacheHit,
    ]);
});
```

| Event | When | Use it for |
|---|---|---|
| `QuestionAsked` | After the input guard, before any spending | Cost attribution, your own quotas, audit |
| `QuestionAnswered` | A successful answer | Usage dashboards, slow-query review, "answered badly" queues |
| `QuestionFailed` | An error — **not** a clarification | Alerting. `errorCode` separates a provider outage from an unanswerable question |
| `UnsafeSqlRejected` | The validator refused generated SQL | Security. Should be near-silent; a burst from one user is not |

`QuestionAnswered` carries a **row count, never the rows**. A listener that
wants the data can re-run the SQL — putting result rows on an event walks them
into log drivers, queue payloads and error trackers, which is the one direction
this package exists to keep data out of.

### What a question cost

`metadata.usage` reports tokens whenever the provider says, in either dialect
(Gemini's `usageMetadata`, OpenAI's `usage`):

```jsonc
"usage": { "prompt_tokens": 1200, "completion_tokens": 80,
           "thinking_tokens": 25, "total_tokens": 1305, "calls": 1 }
```

Counts **accumulate across every call one question took** — a fallback, a
retry, the steps of a decomposed question — because those are one question to
the user. A cache hit reports nothing, which is the point of the cache, and a
provider that returns no usage block reports nothing rather than zero: an
omitted figure is honest, a zero understates a bill.

This matters because `limits.queries_per_day` counts **questions**, which is a
rough proxy — a question against a two-table schema and one against a
fourteen-table schema differ by an order of magnitude in prompt tokens. Use the
event to meter what you are actually spending.

Custom providers opt in by implementing `Contracts\ReportsUsage`; those that
do not are unaffected.

## Building your own front end

The widget is a reference implementation, not the product. Most applications
will build their own in React, Vue, Inertia or Blade, and everything the widget
does goes through the same public REST endpoints.

**→ [docs/API.md](docs/API.md) — the full HTTP reference.** Every endpoint,
every response field, the error codes and their HTTP statuses, the conversation
state shape, and CORS/token setup for a front end on a different origin.

The response shape is pinned by `tests/Feature/ApiContractTest.php`, so fields
do not disappear between releases without a changelog entry.

Three things worth knowing before you start:

- **`parsed_query` and `state_summary` are not debug output.** They state which
  measure, breakdown, filters and dates were actually used. Showing them is how
  a user catches a misreading instead of believing a wrong number.
- **Branch on `error_code`, never on the message.** `retryable` tells you
  whether trying again can possibly help.
- **A different origin needs CORS.** Without it the browser blocks the response
  before your code runs, and it looks like a network fault rather than a policy
  one. `php artisan naturalquery:doctor` checks for this.

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

With several schema files loaded, the package works out which one a question is
about. Routing is for the cases where the wording alone is not a giveaway - map
a word users say to the dataset that answers it:

```php
// config/naturalquery.php
'query_routing' => [
    'ticket'    => 'support_tickets',
    'churn'     => 'subscriptions',
    'revenue'   => 'orders',
],

'system_instructions' => "
    This app has 3 datasets.
    Support and helpdesk questions use support_tickets.
    Anything about plans, renewals or churn uses subscriptions.
    Sales and revenue questions use orders.
",
```

Routing picks the dataset a question *starts* from; it does not stop a query
from reaching other tables. If the tables are related, the answer can still span
them - see [Working with many tables](#working-with-many-tables).

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

**Counting is built in.** Every dataset gets a `record_count` metric
(`COUNT(*)`) without declaring anything, so "how many orders by status" is
answered by counting rows rather than by falling back to whichever measure is
default and reporting revenue per status instead. Declare your own
`record_count` in `computed_metrics` to override it - counting distinct
customers is not counting rows:

```php
'computed_metrics' => [
    'record_count' => [
        'expression' => 'COUNT(DISTINCT customer_id)',
        'description' => 'Number of distinct customers',
        'unit' => 'customers',
    ],
],
```

Set `sql.implicit_count_metric` to `false` in the config to remove the built-in
entirely.

### Time periods

Declare which column a period applies to. A table often has several dates -
`order_date`, `shipped_at`, `created_at` - and they answer different questions,
so the choice is yours rather than a guess from the wording:

```php
'tables' => [
    'primary' => [
        'name' => 'orders',
        'date_column' => 'order_date',   // written for you by discover
        'columns' => [ ... ],
    ],
],
```

"Revenue last month", "orders in 2025", "sales since April" then narrow
properly. The model resolves the wording to actual dates (it is told today's
date, since it has no reliable idea what day it is); those dates are checked
against a strict `YYYY-MM-DD` pattern and passed as bound parameters. A period
that cannot be parsed, or one asked for on a dataset with no date column, is
refused - answering over all time instead would be a confidently wrong number.

### Beyond the intent contract

Intent mode expresses a deliberate subset of SQL: one measure, one breakdown,
one name filter, one period, an order and a limit. That covers most questions
and is safer than free-form SQL - deterministic, no invented column names.

Some questions need more, and the failure mode used to be silent. There is no
`HAVING` in that list, so "customers with more than 10 orders" became
"customers", ranked. No negation, so "excluding cancelled" was dropped and
cancelled orders counted toward the total.

In `auto` mode those questions now go straight to SQL generation, which can
express arbitrary (still validated, still SELECT-only) SQL:

| Wording | Needs | 
|---|---|
| "customers with more than 10 orders" | `HAVING` |
| "orders over 5000" | numeric `WHERE` |
| "revenue excluding cancelled" | negation |
| "how many different customers" | `DISTINCT` |
| "what percentage of orders were cancelled" | ratio of aggregates |
| "top 2 customers in each region" | window function |

This costs nothing - intent parsing and SQL generation are one API call each -
and `metadata.escalated_for` reports which of these triggered it. Set
`sql.escalate_beyond_intent` to `false` to disable. An explicit `query_mode`
of `intent` is always honoured as written.

### Breakdowns

Any column marked `groupable` can be the dimension of a question: "revenue by
region", "orders per status". The schema's `group_column` is only the default
used when the question does not name one. A breakdown by a column that is not
`groupable` is refused with the list of what is available, rather than being
quietly answered with the default grouping.

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

### Relationships

`required_join` above is unconditional - it is applied to every query for that
dataset. `relationships` is the opposite: a list of joins the model *may* use
when a question needs a column from another table.

```php
'tables' => [
    'primary' => [
        'name' => 'orders',
        'columns' => [...],
        'relationships' => [
            [
                'column' => 'customer_id',        // column on THIS table
                'references_table' => 'customers', // table it points at
                'references_column' => 'id',
                'constraint' => 'orders_customer_id_fkey', // optional
            ],
        ],
    ],
],
```

`php artisan naturalquery:discover` writes this for you from the real foreign
keys in your database, so most people never type it by hand.

**Composite keys** are several entries that share one `constraint` name. They
are rendered to the model as a single join with `AND`, because joining on half
a composite key silently matches rows it should not and inflates every total:

```php
'relationships' => [
    ['column' => 'tenant_id',   'references_table' => 'regions',
     'references_column' => 'tenant_id',   'constraint' => 'sales_region_fkey'],
    ['column' => 'region_code', 'references_table' => 'regions',
     'references_column' => 'region_code', 'constraint' => 'sales_region_fkey'],
],
```

**A join is only offered for a table that has its own schema file.** Generated
SQL is checked against a whitelist built from your schema files, so a join to a
table you have not described would be rejected. Rather than suggest a join that
cannot run, the package stays quiet about it. If a relationship you expected is
being ignored, the referenced table has no schema file yet:

```bash
php artisan naturalquery:discover --table=customers --merge
```

### LLM Instructions

The most important field for accuracy. Plain English instructions for the AI:

```php
'llm_instructions' => "
    Revenue means SUM(quantity * unit_price), never the 'amount' column -
    'amount' is stored in cents and excludes tax.

    Always exclude cancelled orders (status = 'cancelled') unless the
    question asks about cancellations.

    Customer names live in the customers table; join it rather than
    grouping by customer_id.
    Use ROUND(value::numeric, 2) for PostgreSQL decimals.
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

## Working with many tables

A realistic Laravel application has dozens of tables, and the first question
anyone asks spans several of them - the name is in `customers`, the money is in
`orders`. Here is what the package does on its own and where you come in.

**Discovery does the mechanical part.** One command reads every table, its
columns, and its real foreign keys, and writes one schema file per table:

```bash
php artisan naturalquery:discover
```

Foreign keys become [`relationships`](#relationships), so the model is told how
the tables connect rather than being left to guess that `customer_id` is a
number worth totalling. Re-run it after a migration with `--merge` to pick up
schema changes without discarding descriptions and aliases you have written.

**Every table goes into the prompt, and that is affordable.** A 40-table
database produces a prompt of roughly 4,200 tokens, against about 600 for a
single table. At current flagship prices that is a fraction of a cent per
question, and well inside every provider's context window. Tested on a 40-table
database, questions requiring a ten-table join chain were answered correctly
without any hand-written configuration.

**Cost is not the limit; ambiguity is.** And it is worth being precise about
which part you have to supply, because it is smaller than people expect.

Measured on a 14-table schema, asking the same 14 questions twice:

| | out of the box | with 3 sentences of config |
|---|---|---|
| 1-table questions | 5/5 | 5/5 |
| 2-table questions | 3/4 | 2/4 |
| 3-table questions | 2/3 | **3/3** |
| 4-table questions | 1/2 | **2/2** |
| overall | 79% | **86%** |

**You do not need to hand-write joins.** Discovery reads your real foreign keys
and the joins were right in every case above - a four-table path from order
lines through orders and customers to regions was found unaided.

What the package cannot know is which measure you mean. That schema had both
`order_items.line_total` and `payments.amount`; asked for revenue, the model
chose payments, so customers who had not paid vanished from the ranking and a
region came back at half its real revenue. The columns are identical in kind -
both decimal, both legitimately related to orders. Only the business knows.

Saying so took three sentences:

```php
// config/naturalquery.php
'system_instructions' => "
    Revenue means SUM(order_items.line_total). Never use payments.amount as
    revenue — payments record what has been collected, not what was sold.
    sessions, jobs and audit_log are infrastructure tables and never answer a
    business question.
",
```

That is the shape of the work: the package gives you a working base from your
own database, and you add the handful of things only you know on top.
`php artisan naturalquery:doctor` tells you when more than one dataset could
answer the same question, so you know when this is worth writing.

Three fields cover nearly everything else, in increasing order of effort:

| Field | Use it when |
|---|---|
| `aliases` on a column | Users say "revenue", the column is `total_amt` |
| `llm_instructions` | A rule the model cannot infer: "always exclude cancelled orders", "amounts are in cents" |
| `example_queries` | One correct SQL example of the join path you want preferred |

**Narrow the surface if you want to.** You do not have to expose everything:

```bash
php artisan naturalquery:discover --table=orders --table=customers
```

Only tables with a schema file are queryable - the generated SQL is validated
against a whitelist built from those files. Joins are only suggested between
tables you have described, so a partial install stays coherent rather than
producing SQL the validator then rejects.

**When an answer is wrong, correct it once.** Submitted corrections are stored
and fed back into later prompts as examples, so a join path you fix by hand
becomes the one the model reuses. See [Feedback / Training](#feedback--training).

## Chatbots: multi-step answers and follow-ups

Two features exist for conversational front-ends. Both are on by default and
both appear in the ordinary `/text` and `/conversation` responses - there is no
separate endpoint to call.

### Questions that need more than one query

"Compare revenue this year with last year" is two queries and a subtraction.
Answered as one it returns whichever half the model wrote SQL for, and drops
the other silently. So a question like that is split, each part is answered
independently, and the results are combined:

```json
{
  "status": "success",
  "type": "multi_step",
  "answer": "Line revenue is 21,011,088 (revenue in 2026) versus 18,244,900 (revenue in 2025) — up 15.2%.",
  "steps": [
    { "n": 1, "question": "revenue in 2026", "status": "success",
      "answer": "Total revenue: 21,011,088", "rows": [ ... ] },
    { "n": 2, "question": "revenue in 2025", "status": "success",
      "answer": "Total revenue: 18,244,900", "rows": [ ... ] }
  ],
  "comparison": { "change_pct": 15.2, "direction": "up",
                  "first_value": 21011088, "second_value": 18244900 }
}
```

Three things worth knowing:

**Every step is a real query with every guard applied.** Each one is intent
parsed, validated against the schema-derived table whitelist, and executed like
a question typed on its own. Decomposition adds a planning call; it does not
add a second route into your database.

**Ordinary questions cost exactly what they cost today.** Planning only runs
when the wording suggests a comparison - "vs", "compare", "difference between",
"year on year". "Top 5 customers by revenue" never triggers it. A missed
decomposition simply behaves as it did before; that is the safer direction to
fail in.

**The arithmetic is done in PHP, on values already fetched.** A percentage is
never produced by asking a model to subtract two numbers, and never at all
unless the two steps measure the same metric and each returned a single value.
A ranking compared against a total yields `"comparison": null` rather than an
invented figure.

### Suggested follow-ups

The hard part of asking your data questions in words is not phrasing one - it
is knowing what can be asked at all. Every answer carries a few concrete next
questions:

```json
"next_steps": [
  { "label": "Break West down by product category",
    "query": "revenue by product_category for West" },
  { "label": "Show order counts by region", "query": "how many by region" },
  { "label": "Bottom 5 instead",            "query": "bottom 5 regions by revenue" }
]
```

These are derived from your schema, not from the model: no API call, no added
latency, and they can only propose breakdowns the validator would accept. The
bundled widget renders them as buttons; a custom chat UI can do the same.

```php
// config/naturalquery.php
'chat' => [
    'multi_step' => true,               // decompose comparison questions
    'max_steps' => 4,                   // ceiling on queries per question
    'suggest_next_steps' => true,
    'max_next_steps' => 4,
    'suggest_drilldown_values' => true, // may a suggestion name a value from the results?
],
```

`suggest_drilldown_values` is the one worth a thought. "Break West down by
category" contains a value read from your data. It is built and returned
locally like every other suggestion, and clicking it sends that question as the
user's own query text - the same as typing it. Set it to `false` if you would
rather no value from your data ever reach a prompt, even by the user's hand.

## Multi-Turn Conversation

A conversation carries a **structured state**, not a transcript. Each turn is
classified, merged into that state in PHP, and validated against your schema
before any SQL is built:

```php
use Jayanta\NaturalQuery\Conversation\ConversationManager;

$conv = app(ConversationManager::class);

$conv->query('session-123', 'top 5 customers by revenue');
// state: revenue · by customer name            [new_query]

$conv->query('session-123', 'only in West');
// state: revenue · by customer name · region is West       [refinement]

$conv->query('session-123', 'break that down by status');
// state: revenue · by status · region is West              [drill_down]

$conv->query('session-123', 'how many orders by region');
// state: record count · by region                          [new_query]
//   ↑ inherits nothing: it names its own measure
```

**Turn classification comes first**, because the failure people actually
notice is a new question treated as a refinement - they ask something
unrelated and get results still filtered by a region from three turns ago.
Anything that names its own measure or dataset is a `new_query`, whatever it
starts with.

| Type | Example | What happens |
|---|---|---|
| `new_query` | "show me pending orders" | Fresh state, inherits nothing |
| `refinement` | "only in West", "last month" | Merged into the state |
| `drill_down` | "break that down", "in which category" | Adds a breakdown |
| `reference` | "why is that?", "explain" | Same query, different output |

**Every answer reports the state it was understood as.** `state_summary`
("revenue · by customer name · region is West") is rendered above the answer by
the widget, so a misread is caught rather than trusted, and "no, I meant X"
corrects something visible.

**And whether the turn stood alone.** Beside the state the widget shows
**New topic**, **Follow-up**, **Drill-down** or **Same query**, from
`conversation.classification`, tinted when context was carried.

The state summary cannot convey this by itself. Ask "total amount by city" then
"breakdown by client" and both read `amount · by client` whether the second
inherited the measure or worked it out afresh — identical text, two different
things happening. And when a filter *is* inherited, knowing it was inherited
rather than asked for is the difference between trusting a number and checking
it.

**Going back is a restore, not a re-interpretation.** Every turn's state is
kept:

```php
$conv->rewind('session-123');          // step back one turn
// POST /naturalquery/conversation/{session}/rewind
```

The widget offers this as **Undo last step**, appearing when the server reports
there is history behind the current turn. It matters more than it looks: if the
only correction on offer is clearing the conversation, undoing "only in West"
means retyping everything that came before it, so people start over instead of
refining — the exact behaviour these endpoints exist to make unnecessary.

**A page reload keeps the conversation.** The state lives on the server under a
session id, so the widget persists that id per tab and asks
`GET /conversation/{session}` on mount, reporting what is still in force. The
rendered answers are not stored server-side and the thread does not come back —
it says what it picked up rather than implying the screen was restored, because
the next follow-up resolves against those filters either way.

**Slots are validated against your schema before any SQL is built.** A metric
that doesn't exist, or a breakdown that isn't a dimension, becomes a clarifying
question - never a query. A confidently wrong number is worse than "I'm not
sure what you mean by that", and by a wide margin when the number will be read
as fact.

**Refinements are capped** at `conversation.max_refinements` (default 6). Past
that, ambiguity compounds faster than anyone notices, so the user is asked to
start fresh rather than served something confident and wrong. Rewind still
works.

Follow-ups are never answered from, or written to, the query cache: "only in
West" means one thing after a revenue question and another after an order
count.

## Feedback / Training

Users can submit corrections. These are fed into future prompts so the AI learns:

```bash
curl -X POST /naturalquery/feedback \
  -d '{"query":"show top customers","scheme":"orders","correction":"Should rank by SUM(quantity * unit_price), not the amount column","feedback_type":"wrong_metric"}'
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

### Spending

Every question is a paid API call, so the routes carry a ceiling as well as a
rate. `throttle:60,1` stops a burst; it does not stop a slow drain - sixty a
minute sustained is roughly 86,000 questions a day.

```php
// config/naturalquery.php
'limits' => [
    'queries_per_day' => 200,   // per user, or per IP when routes are public
],
```

Counted per authenticated user, or per IP when there is none, and reset at
midnight. Reaching it returns HTTP 429 naming the limit, so clients that
already handle rate limiting handle this too. Set it to `null` for no ceiling -
a choice worth making deliberately.

The limit is applied by the package itself rather than through
`routes.middleware`, so customising that array - the first thing anyone does to
make the widget public - cannot drop the ceiling by accident.

**Removing `auth` deserves a moment's thought.** Without it the widget is an
LLM proxy anyone who finds the URL can use, and the daily ceiling falls back to
counting by IP, which is easy to get around. `naturalquery:doctor` says so if
you do it.

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
retry path, multi-turn follow-ups and clarifications. Every case also asserts
the query genuinely returned sentinel-bearing rows, so a query that quietly
failed cannot pass by transmitting nothing. One case asserts the package has no
way to receive audio at all, since speech never reaches the server.

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

XAMPP already ships a bundle at `C:\xampp\apache\bin\curl-ca-bundle.crt`, so
usually no download is needed. `php artisan naturalquery:doctor` now checks
whether PHP has a store at all and prints the exact path if it finds one on
your machine — worth running first, because the symptom does not look like a
certificate problem. Every question comes back as a provider failure, and
before this was caught properly a whole benchmark run scored 0/36 and read
exactly like a broken package.

**`Driver '<name>' is connected but NaturalQuery cannot introspect it`** -
NaturalQuery reads your schema through database introspection, and ships with
SQLite, PostgreSQL and MySQL/MariaDB. If your app runs on something else, point
NaturalQuery at a connection it can read:

```php
// config/naturalquery.php
'sql' => [
    'database_connection' => 'pgsql',   // a connection from config/database.php
],
```

…or teach it yours. That is a config change, not a fork - implement
`Contracts\SchemaIntrospectorInterface` and register the class:

```php
'sql' => [
    'introspectors' => [
        'sqlsrv' => \App\NaturalQuery\SqlServerIntrospector::class,
        'sqlite' => null,   // null disables a built-in driver
    ],
],
```

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
