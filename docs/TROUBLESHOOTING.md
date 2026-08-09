# Troubleshooting

The errors people actually hit, and what each one means.

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
usually no download is needed. Run `php artisan naturalquery:doctor` first — it
checks whether PHP has a CA store at all and prints the path of one it finds on
your machine. Worth doing before anything else, because the symptom does not
look like a certificate problem: every question simply fails, which reads like
a broken package rather than a missing file.

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
