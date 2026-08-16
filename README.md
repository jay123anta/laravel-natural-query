# Laravel NaturalQuery

**Let people ask your database questions in English - by voice or by typing -
without your data ever leaving your server.**

[![Tests](https://github.com/jay123anta/laravel-natural-query/actions/workflows/tests.yml/badge.svg)](https://github.com/jay123anta/laravel-natural-query/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

[![Packagist](https://img.shields.io/packagist/v/jayanta/laravel-natural-query.svg)](https://packagist.org/packages/jayanta/laravel-natural-query)
[![Downloads](https://img.shields.io/packagist/dt/jayanta/laravel-natural-query.svg)](https://packagist.org/packages/jayanta/laravel-natural-query)
[![PHP](https://img.shields.io/packagist/dependency-v/jayanta/laravel-natural-query/php.svg)](https://packagist.org/packages/jayanta/laravel-natural-query)

The AI is sent your **schema structure only** - table names, column names,
types, and the words your users use for them. It returns SQL. Your server
validates that SQL, runs it locally, and formats the rows. **Not one row is
ever sent upstream**, and that is enforced by tests, not by intent.

**Any model, hosted or your own.** Gemini, Claude, OpenAI, DeepSeek, Mistral,
Groq, OpenRouter - or a model you run yourself on Ollama, vLLM, LM Studio or
llama.cpp. One config block, no code changes. Self-hosting goes further still:
nothing leaves your network at all, not even the schema.

```php
$result = NaturalQuery::query("top 5 customers by revenue");
```

Or drop the whole UI - chat thread, microphone, charts - into any Blade view:

```blade
<x-naturalquery::widget />
```

---

## Install

Requires **PHP 8.2+** and **Laravel 12 or 13**. Works on PostgreSQL, MySQL,
MariaDB and SQLite.

```bash
composer require jayanta/laravel-natural-query
php artisan naturalquery:install
php artisan migrate
```

Choose a model in `.env`. A model you run yourself is a first-class choice, not
a fallback:

```env
# Local, no API key, nothing leaves your machine
NATURALQUERY_LLM_DRIVER=ollama
OLLAMA_MODEL=llama3.3

# Or a hosted API
NATURALQUERY_LLM_DRIVER=gemini
GEMINI_API_KEY=your-key-here
```

Built-in drivers: `ollama`, `gemini`, `openai`, `claude`. Any other
OpenAI-compatible service - DeepSeek, Groq, Mistral, OpenRouter, vLLM,
LM Studio, LocalAI - plugs in with a `base_url` and a `model`; see
[docs/PROVIDERS.md](docs/PROVIDERS.md).

## Teach it your data

```bash
php artisan naturalquery:discover --ai
```

This is the whole adaptation step. The package knows nothing about your
application: this reads *your* database and writes one plain PHP file per table
into `config/naturalquery-schemas/`. Those files are the only thing that makes
it understand your domain - no code changes, no subclassing.

`--ai` also fills in the human layer that cannot be read from a database:
descriptions, the words your users actually say, business rules, and computed
metrics like averages. **Worth doing** - without it, a question like "average
amount" costs an extra API call to answer.

Then check it:

```bash
php artisan naturalquery:doctor
```

It names the real cause of any problem and prints the exact fix. Run it first
whenever something is wrong.

## Ask a question

```php
use Jayanta\NaturalQuery\Facades\NaturalQuery;

$result = NaturalQuery::query('total revenue by region last month');
```

```jsonc
{
  "status": "success",
  "answer": "Revenue by region: West 2,028,763; East 1,878,404",
  "speech_text": "Revenue by region. West, 2 million…",   // phrased to be read aloud
  "rows": [ { "region": "West", "revenue": "2028763.00" } ],
  "parsed_query": {
    "metric": "revenue", "group_by": "region",
    "filters": [], "period": "2026-07-01 to 2026-07-31"
  }
}
```

**Show users how the question was read.** Every answer also carries
`parsed_summary` - the same information as one line, `"Orders · revenue · by
region · status is pending"` - and the bundled widget puts it under each
answer. It is the difference between someone catching a misreading and
believing a number that answers a different question. Use `parsed_query` when
you want the structure instead.

Or over HTTP, which is what the widget uses:

```bash
POST /naturalquery/text          {"text": "top 5 customers by revenue"}
POST /naturalquery/conversation  {"session_id": "abc", "text": "only in West"}
```

## Voice

**The browser listens. Your server only ever receives text.**

```blade
<x-naturalquery::widget />   {{-- the microphone is already there --}}
```

There is nothing to configure and no audio endpoint. The widget uses the
browser's `SpeechRecognition` to turn speech into English text on the device,
then posts that text exactly as if it had been typed. Three things follow from
that one decision:

- **It works with every model** - Gemini, Claude, Ollama, anything - because by
  the time the model is involved it is reading a sentence, not hearing a
  recording.
- **No audio leaves the device.** Not to your server, not to a provider. There
  is no upload path in the package at all.
- **Nothing extra to set up or pay for** - no transcription service, no second
  API key, no added latency.

Answers carry a `speech_text` field phrased for reading aloud, and the widget
speaks it. Chrome, Edge and Safari support recognition; Firefox does not, so
the microphone is hidden there and people type - which is why text input is
never optional.

`language` picks which English **accent** to listen for - `en-IN` recognises
Indian English far more accurately than `en-US` does:

```blade
<x-naturalquery::widget language="en-IN" />
```

English only, on purpose. Multilingual belongs to a separate package with a
speech pipeline of its own; this one stays an English natural-language-to-SQL
assistant. → [docs/WIDGET.md](docs/WIDGET.md)

## Who is allowed to ask

These endpoints spend your API key, so they are not open by default.

| | Who gets in |
|---|---|
| A `viewNaturalQuery` gate you define | Whatever the gate says |
| No gate, `local` or `testing` | Everyone - so it works the moment you install it |
| No gate, anywhere else | Signed-in users only |

```php
// AppServiceProvider::boot()
Gate::define('viewNaturalQuery', fn ($user) => $user->isAdmin());
```

Define the gate as soon as this is more than you: an ungated endpoint in
production is an LLM proxy for the internet.

---

## What it is good at, and what it is not

**It works well** on datasets you have described. Told that `revenue` is a
measure to total, that users say "client" for `customer_name`, and that
cancelled orders do not count, it is reliable for the questions those datasets
are meant to answer.

**It is not magic.** Pointed at an undescribed database and asked something
vague, any text-to-SQL system will sometimes produce a confident, wrong answer.

Measured against the Spider benchmark - real questions, unfamiliar databases,
no curation - it answers **29 of 36 (81%)**. Read that as: roughly one question
in five is wrong on an *uncurated* schema. On a described one it is far better,
which is why the schema files matter more than anything else you will do.

The honest framing is **a fast analyst for datasets you have curated**, not an
oracle for arbitrary databases. Every mitigation here follows from that: SQL is
SELECT-only and restricted to your tables, `doctor` catches schema drift, and
every answer shows the query it understood.

### Provider conformance

Seventeen cases whose answers are arithmetic on three seeded rows - totals,
filters, averages, periods, a decomposed comparison, and a conversation that
narrows, drills down and rewinds:

| Model | Result |
|---|---|
| Gemini 2.5 Flash | 17/17 |
| Claude Sonnet 5 | 17/17 |
| DeepSeek v4 Flash | 17/17 |
| Mistral Large | 17/17 |
| Llama 3.3 70B (open weights) | 17/17 |
| Llama 3.1 8B (open weights) | 12/17 |

**Model size matters more than vendor.** The 70B open-weight model scores the
same as the four frontier hosted ones, and runs on a single good GPU. The 8B
drops filters and ignores date periods - asked for July it returns the whole
table, confidently - so **use a 70B-class model or better wherever a wrong
number matters.**

Conversation state is the exception worth noting: narrowing, drill-down and
rewind pass even on the 8B, because they are resolved in PHP rather than left
to the model.

```bash
NATURALQUERY_CONFORMANCE=1 NATURALQUERY_LLM_DRIVER=claude \
NATURALQUERY_CONFORMANCE_KEY=sk-... vendor/bin/phpunit --testsuite Conformance
```

Run any battery more than once before believing it. On a free tier the first
pass often measures the rate limit rather than the model - add
`NATURALQUERY_CONFORMANCE_DELAY=15` to space the calls out.

---

## Documentation

| | |
|---|---|
| [docs/SCHEMA.md](docs/SCHEMA.md) | Schema files in full - metrics, aliases, joins, many tables |
| [docs/API.md](docs/API.md) | Every endpoint, field and error code - plus events and token cost |
| [docs/CONVERSATIONS.md](docs/CONVERSATIONS.md) | Follow-ups, drill-downs, rewind, multi-step answers |
| [docs/PROVIDERS.md](docs/PROVIDERS.md) | Every LLM driver, and adding your own |
| [docs/WIDGET.md](docs/WIDGET.md) | The bundled UI and browser voice input |
| [docs/SECURITY.md](docs/SECURITY.md) | The privacy wall, SQL validation, prompt-injection guard |
| [docs/CACHING.md](docs/CACHING.md) | What is cached, when a row is reused, replacing the cache |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | What each error means and how to fix it |

## Commands

```bash
php artisan naturalquery:install        # publish config and migrations
php artisan naturalquery:discover       # write schema files from your database
php artisan naturalquery:audit-schema   # what the AI still has to guess — do this next
php artisan naturalquery:doctor         # diagnose setup problems, print the fix
php artisan naturalquery:benchmark      # how accurate is it on YOUR schema?
php artisan naturalquery:debug "…"      # the exact prompt, and which route it takes
php artisan naturalquery:cache-stats    # is the cache earning its keep?
php artisan naturalquery:cache-cleanup  # prune it
```

`discover` → `audit-schema` → write the descriptions it asks for → `benchmark`
is the loop that moves accuracy. The audit says what the model is guessing; the
benchmark tells you what fixing that was worth, on your own data.

## Contributing

`vendor/bin/phpunit` must pass and the widget must pass `node --check`. New
behaviour gets a test; every failure a real user hits becomes a regression
test.

## License

MIT. See [LICENSE](LICENSE).
