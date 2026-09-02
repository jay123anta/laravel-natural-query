<!-- All five badges on ONE physical line, deliberately. Split across lines
     they render as one row on GitHub and as five stacked rows on Packagist,
     which uses a different Markdown renderer that treats a single newline as
     a break. One line renders identically everywhere. Do not reflow. -->
[![Packagist](https://img.shields.io/packagist/v/jayanta/laravel-natural-query.svg)](https://packagist.org/packages/jayanta/laravel-natural-query) [![Downloads](https://img.shields.io/packagist/dt/jayanta/laravel-natural-query.svg)](https://packagist.org/packages/jayanta/laravel-natural-query) [![Tests](https://github.com/jay123anta/laravel-natural-query/actions/workflows/tests.yml/badge.svg)](https://github.com/jay123anta/laravel-natural-query/actions/workflows/tests.yml) [![PHP](https://img.shields.io/packagist/dependency-v/jayanta/laravel-natural-query/php.svg)](https://packagist.org/packages/jayanta/laravel-natural-query) [![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

# Laravel NaturalQuery

**A natural-language query box you can put inside your own Laravel app - for
data you are not allowed to send anywhere.**

<p align="center">
  <img src="https://raw.githubusercontent.com/jay123anta/laravel-natural-query/main/docs/demo.gif" alt="Asking a database a question in English -  the model receives only table and column names" width="100%">
</p>



Almost every "ask your data" product works by sending rows to a model. If the
data is under GDPR or DPDP, or belongs to a client who has not agreed to that,
the conversation ends there.

This one sends the model your **schema structure only** - table names, column
names, types, and the words your users use for them. It returns SQL. Your
server validates that SQL, runs it locally, and formats the rows. **Not one row
is ever sent upstream**, and that is enforced by tests, not by intent.

Three things follow that a hosted tool cannot offer:

- **You can ship it to your own users.** It is a Blade component inside your
  application, not a separate analytics seat they have to buy and log in to.
- **The data-protection conversation is short.** What leaves is schema
  structure and the question someone typed. Nothing else has a path out - see
  [docs/SECURITY.md](docs/SECURITY.md) for how that is enforced and tested.
- **It can run with nothing leaving your network at all.** Point it at a model
  on your own hardware and even the schema stays in-house.

**Any model, hosted or your own.** Gemini, Claude, OpenAI, DeepSeek, Mistral,
Groq, OpenRouter - or a model you run yourself on Ollama, vLLM, LM Studio or
llama.cpp. One config block, no code changes.

People can ask by typing or by speaking - the browser does the listening, so no
audio reaches your server either.

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
  "parsed_summary": "Orders · revenue · by region · 2026-07-01 to 2026-07-31",
  "parsed_query": {
    "metric": "revenue", "group_by": "region",
    "filters": [], "period": "2026-07-01 to 2026-07-31",
    "date_from": "2026-07-01", "date_to": "2026-07-31"
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

That last one is a rule, not a hint. A `required_filter` in a schema file is
**enforced where the SQL is executed** - a generated query that omits it is
refused rather than answered, on every route that reaches your database,
including a cached one replayed later. It is the one setting whose whole
purpose is that the answer is wrong without it, so it is not left to the model
to remember. → [docs/SCHEMA.md](docs/SCHEMA.md)

**It is not magic.** Pointed at an undescribed database and asked something
vague, any text-to-SQL system will sometimes produce a confident, wrong answer.

Two benchmarks, both run against uncurated schemas, both reproducible:

| Benchmark | Score | What it is |
|---|---|---|
| Spider dev sample | **29/36 (81%)** | Real questions and gold SQL from the Spider set, two unfamiliar databases |
| This package own set | **30/46 (65%)** | 46 questions over a 14-table schema, up to four-table joins |

The second is lower because it is harder: more joins, more periods, and
questions like *which orders have not shipped* where a wrong join silently
returns everything. Both numbers are published because quoting only the
friendlier one is the kind of thing anybody can check in five minutes.

Read them as: **roughly one question in five is wrong on an uncurated schema,
and closer to one in three once the joins get deep.** Describing your schema
moves it - measurably, and you can measure it yourself below - but it is a
correction, not a cure.

Where it is weakest, from those runs: superlatives that need a LIMIT
("which carrier shipped the most"), HAVING clauses, and anti-joins
("customers who have never ordered"). Those are limits of the approach, not
of your schema, and no amount of curation fixes them.

The honest framing is **a fast analyst for datasets you have curated**, not an
oracle for arbitrary databases. Every mitigation here follows from that: SQL is
SELECT-only and restricted to your tables, `doctor` catches schema drift, and
every answer states how it read the question.

**You do not have to take that on trust for your own data.** Two commands close
the loop:

```bash
php artisan naturalquery:audit-schema   # what the model is still guessing
php artisan naturalquery:benchmark      # how often it is right, on your schema
```

The audit names the things introspection cannot recover - a column nobody
described, two columns that could both be "revenue", a table your users call
something else. The benchmark runs your own questions against SQL you wrote by
hand and compares the results. Curate what the audit found, run the benchmark
again, and the difference is a number you produced rather than one this README
asserts.

### Provider conformance

Seventeen cases whose answers are arithmetic on three seeded rows - totals,
filters, averages, periods, a decomposed comparison, and a conversation that
narrows, drills down and rewinds:

| Model | Result |
|---|---|
| Gemini 2.5 Flash | 17/17 |
| Claude Sonnet 5 | 17/17 |
| DeepSeek v4 Flash | 17/17 |
| DeepSeek v4 Flash, via an OpenAI-compatible router | 17/17 |
| `gpt-oss-20b` (open weights) | 17/17 |
| Mistral Large | 17/17 |
| Muse Glimmer 30B (open weights) | 15/17 |
| Nemotron 3 Super 120B (open weights) | 15/17 |

**Capability does not track parameter count, and models fail differently.**
A 20B scored 17/17 including the multi-step decomposition ("compare July with
August") that a 120B failed. Where models do miss, they miss in different
places: one answers a scalar question with an unrequested breakdown - asked for
*the* average it returns an average per client - while another handles single
questions cleanly and cannot decompose a comparison into steps.

So there is no single number to rank models by for this job. The battery takes
about a minute; **run it against the model you actually intend to use** rather
than inferring from size or vendor.

**Small local models are the one case to be careful with.** Below roughly 20B,
expect dropped filters and ignored date periods - asked for July, the whole
table comes back, confidently. If you are running a model on your own hardware
and a wrong number matters, measure it before you trust it.

Conversation state is the exception worth noting: narrowing, drill-down and
rewind hold up even on small models, because they are resolved in PHP rather
than left to the model.

The fourth row is the portability claim, measured: a service this package has
no built-in support for, reached with nothing but a `base_url` and a model
name.

```bash
NATURALQUERY_CONFORMANCE=1 NATURALQUERY_LLM_DRIVER=claude \
NATURALQUERY_CONFORMANCE_KEY=sk-... vendor/bin/phpunit --testsuite Conformance
```

Run any battery more than once before believing it. On a free tier the first
pass often measures the rate limit rather than the model - add
`NATURALQUERY_CONFORMANCE_DELAY=15` to space the calls out.

---

## How this differs from the alternatives

**[beyondcode/laravel-ask-database](https://github.com/beyondcode/laravel-ask-database)**
is the package most people find first, and the one this is most often compared
to. It asked the same question in 2023, collected 302 stars, and was
**archived in February 2024**. It calls OpenAI's GPT-3 specifically, needs an
OpenAI key, and its own README describes it as a learning resource for prompt
engineering rather than something to run.

If you arrived here after finding that one archived, this is the maintained
answer to the same question -  and a different shape of answer: any provider or
a model on your own hardware, SELECT-only validation against a schema-derived
whitelist, conversation state, and a privacy wall that is tested rather than
promised.

**[prism-php/prism](https://packagist.org/packages/prism-php/prism)** and
**[openai-php/laravel](https://packagist.org/packages/openai-php/laravel)** are
the layer *below* this one. They give you a clean, provider-agnostic way to
call a model from Laravel. They do not know what a dataset is, will not stop a
`DROP TABLE`, and have no opinion about whether a row reaches the provider.
NaturalQuery is a vertical built on that idea: schema introspection, a SQL
validator, a two-tier cache, conversation state, and a privacy wall. If you
want to call an LLM, use Prism. If you want to let people ask your database
questions, use this.

**Hosted text-to-SQL** -  the analytics products with an "ask your data" box - 
send your schema *and usually your rows* to a third party, and price per seat.
This runs in your application, sends schema structure only, and can run
entirely offline against Ollama.

**Writing it yourself.** Entirely reasonable, and most of it is a weekend.
The parts that are not: SELECT-only validation against a schema-derived
whitelist, a cache that cannot answer one question with another question's
result, rate limits reported as rate limits, and a benchmark that tells you how
often you are wrong. Those took this package many adversarial review rounds and
742 tests, and every one of them exists because something went wrong first.

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
php artisan naturalquery:audit-schema   # what the AI still has to guess -  do this next
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

`vendor/bin/phpunit`, `vendor/bin/pint` and `vendor/bin/phpstan analyse` must
pass, and the widget must pass `node --check`. New behaviour gets a test you
have watched fail; every failure a real user hits becomes a regression test.
See [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md) for
anything that should not be a public issue.

## License

MIT. See [LICENSE](LICENSE).
