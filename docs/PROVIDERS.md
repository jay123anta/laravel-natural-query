# Providers

Any model, hosted or your own. Business logic never references a concrete
provider, so adding one is a config block and nothing else.

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
