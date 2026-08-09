# Security and privacy

What reaches the AI, what never does, and the layers between a typed question
and your database.

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
