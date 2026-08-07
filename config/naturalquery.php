<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NaturalQuery - Configuration
    |--------------------------------------------------------------------------
    |
    | Privacy-safe natural language to SQL engine.
    | AI sees only your schema structure, never your actual data.
    |
    | "Query your database naturally. AI generates SQL. Data never leaves."
    |
    */

    // SSL verification for LLM API calls. Accepts three kinds of values:
    //   true  (default)      - verify against the system CA bundle
    //   "/path/to/cacert.pem" - verify against this CA bundle file. This is
    //                          the fix for local stacks (XAMPP/WAMP) whose
    //                          PHP has no CA store: download cacert.pem
    //                          (https://curl.se/ca/cacert.pem) and point this
    //                          at it - no php.ini edit needed.
    //   false                - disable verification (NOT recommended; enables
    //                          man-in-the-middle attacks)
    'ssl_verify' => env('NATURALQUERY_SSL_VERIFY', true),

    // ==========================================================================
    // LLM PROVIDER (AI Engine)
    // ==========================================================================
    // Which AI provider to use for natural language understanding and SQL generation.
    // All providers receive ONLY schema structure - never actual data.
    //
    // Built-in drivers: 'gemini', 'openai', 'claude', 'ollama'
    // PLUS any OpenAI-compatible service (hosted or self-hosted): add a block
    // under 'providers' with a 'base_url' and set the driver to that block's
    // name — see the 'deepseek' and 'selfhosted' examples below. No particular
    // model is ever required; every provider's model is your choice.
    'llm' => [
        'driver' => env('NATURALQUERY_LLM_DRIVER', 'gemini'),

        'providers' => [
            'gemini' => [
                'api_key' => env('GEMINI_API_KEY'),
                // gemini-2.0-flash was RETIRED by Google (returns 404) — keep
                // this default on a live model.
                'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
                'timeout' => env('NATURALQUERY_TIMEOUT', 30),
                'max_retries' => 3,
                // Gemini 2.5+ internal "thinking" budget. 0 (default) disables
                // thinking — low-temperature SQL/intent extraction doesn't need
                // it, and with thinking ON its tokens silently consume
                // maxOutputTokens and truncate the JSON response. Set to -1 in
                // env to restore provider-default thinking.
                'thinking_budget' => ((int) env('GEMINI_THINKING_BUDGET', 0)) === -1
                    ? null
                    : (int) env('GEMINI_THINKING_BUDGET', 0),
                'max_output_tokens' => (int) env('NATURALQUERY_MAX_OUTPUT_TOKENS', 2048),
            ],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                // Any OpenAI-compatible endpoint works here too.
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'timeout' => env('NATURALQUERY_TIMEOUT', 30),
                'max_retries' => 3,
                'max_tokens' => (int) env('NATURALQUERY_MAX_OUTPUT_TOKENS', 2048),
                // Some self-hosted servers reject response_format — set false there.
                'force_json' => true,
            ],

            // ------------------------------------------------------------------
            // BRING YOUR OWN MODEL — hosted or self-hosted
            // ------------------------------------------------------------------
            // Any service speaking the OpenAI chat-completions protocol plugs in
            // by adding a block here and setting NATURALQUERY_LLM_DRIVER to its
            // name. That covers DeepSeek, Groq, Mistral, Together, OpenRouter,
            // and self-hosted stacks: vLLM, LM Studio, LocalAI, llama.cpp
            // server, text-generation-webui. (For Ollama, the dedicated
            // 'ollama' driver below is preferred.)
            //
            // No model is ever fixed by this package — you choose the model on
            // every provider via config/env.
            'deepseek' => [
                'api_key' => env('DEEPSEEK_API_KEY'),
                'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
                'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
                'timeout' => env('NATURALQUERY_TIMEOUT', 30),
                'max_retries' => 3,
                'max_tokens' => (int) env('NATURALQUERY_MAX_OUTPUT_TOKENS', 2048),
                'force_json' => true,
            ],
            'selfhosted' => [
                // Example: vLLM / LM Studio / LocalAI / llama.cpp server.
                // Usually keyless; leave api_key null.
                'api_key' => env('SELFHOSTED_LLM_API_KEY'),
                'model' => env('SELFHOSTED_LLM_MODEL', 'qwen2.5-coder:14b'),
                'base_url' => env('SELFHOSTED_LLM_URL', 'http://localhost:8000/v1'),
                'timeout' => (int) env('NATURALQUERY_TIMEOUT', 60),
                'max_retries' => 2,
                'max_tokens' => (int) env('NATURALQUERY_MAX_OUTPUT_TOKENS', 2048),
                // Many self-hosted servers reject OpenAI's response_format param.
                'force_json' => (bool) env('SELFHOSTED_LLM_FORCE_JSON', false),
            ],
            'claude' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('CLAUDE_MODEL', 'claude-sonnet-4-20250514'),
                'timeout' => env('NATURALQUERY_TIMEOUT', 30),
                'max_retries' => 3,
            ],
            'ollama' => [
                'base_url' => env('OLLAMA_URL', 'http://localhost:11434'),
                'model' => env('OLLAMA_MODEL', 'llama3'),
                'timeout' => env('NATURALQUERY_TIMEOUT', 60),
            ],
        ],
    ],

    // ==========================================================================
    // QUERY MODE
    // ==========================================================================
    // How the AI processes natural language queries.
    //
    // 'intent'          - AI extracts intent (scheme, metric, order) → local SQL builder
    //                     constructs the query. Safer, but limited to predefined metrics.
    //
    // 'sql_generation'  - AI receives full table structure and generates SQL directly.
    //                     More flexible — works with any query pattern. SQL is validated
    //                     before execution to prevent dangerous operations.
    //
    // 'auto'            - Uses intent mode for known schemas with defined metrics.
    //                     Falls back to sql_generation when intent parsing fails.
    //                     Recommended for most projects.
    'query_mode' => env('NATURALQUERY_QUERY_MODE', 'auto'),

    // ==========================================================================
    // DEFAULT SCHEME
    // ==========================================================================
    // If your project has only ONE dataset (or a primary one), set it here.
    // This eliminates scheme detection errors — the AI always knows which tables to use.
    //
    // Examples:
    //   'sales'          → all queries go to the 'sales' schema
    //   null             → AI must detect scheme from query text (multi-scheme projects)
    'default_scheme' => env('NATURALQUERY_DEFAULT_SCHEME', null),

    // ==========================================================================
    // PROJECT-LEVEL AI INSTRUCTIONS
    // ==========================================================================
    // Custom instructions injected into EVERY AI prompt. Use this to tell the AI
    // about your project's domain, terminology, business rules, and quirks.
    //
    // This is the MOST IMPORTANT config for reducing errors in your project.
    // The more context you give, the fewer mistakes the AI makes.
    //
    // Example for a government dashboard:
    //   "This is a government dashboard for Assam state, India.
    //    Districts are administrative regions. There are 35 districts.
    //    'Kamrup Metropolitan' may appear as 'KAMRUP MOHANNAGAR' in database.
    //    Always exclude C&D waste (type='cnd') unless explicitly asked."
    //
    // Example for an e-commerce app:
    //   "This is an e-commerce platform. Orders have line items.
    //    Revenue = SUM(quantity * unit_price). Use order_date for time filters.
    //    Status values: pending, processing, shipped, delivered, cancelled."
    'system_instructions' => '',

    // ==========================================================================
    // QUERY ROUTING (Multi-Schema Projects)
    // ==========================================================================
    // Tell the AI which schema to use for specific keywords/topics.
    // This prevents the #1 error: AI picks wrong schema or can't identify schema.
    //
    // Format: 'keyword or phrase' => 'schema_key'
    // The keyword is checked against the user's query (case-insensitive).
    // Longer phrases are checked first for best matching.
    //
    // Example:
    //   'ticket' => 'support_tickets',      // any helpdesk wording → tickets
    //   'churn' => 'subscriptions',
    //   'abandoned cart' => 'carts',        // longer phrases win over 'cart'
    'query_routing' => [
        // 'keyword' => 'schema_key',
    ],

    // ==========================================================================
    // GLOBAL EXAMPLE QUERIES (Multi-Schema)
    // ==========================================================================
    // Example queries that span MULTIPLE schemas or help the AI understand
    // cross-schema concepts. These are included in multi-scheme prompts.
    //
    // Per-schema examples go in the schema config files.
    // Global examples go here — for routing/disambiguation.
    'global_examples' => [
        // ['natural' => 'Compare support load and revenue', 'note' => 'Use support_tickets for load, orders for revenue'],
        // ['natural' => 'Show overall status of everything', 'note' => 'Query each schema separately and combine results'],
    ],

    // ==========================================================================
    // PROMPT CUSTOMIZATION
    // ==========================================================================
    // Override the default prompt templates. Set to null to use package defaults.
    //
    // Available placeholders:
    //   {system_role}      - AI role description
    //   {system_instructions} - Project-level instructions from above
    //   {schema_info}      - Full table/column structure
    //   {user_query}       - The user's question
    //   {dialect}          - Database dialect (postgresql, mysql, etc.)
    //   {limit_rule}       - LIMIT instruction
    //   {examples}         - Example queries from schema config
    //   {corrections}      - Past corrections from feedback system
    //   {llm_instructions} - Per-schema LLM instructions
    //   {routing_hints}    - Query routing hints from above
    //   {global_examples}  - Global example queries from above
    //
    // Tip: Use naturalquery:debug "your query" to preview the prompt
    'prompts' => [
        // Override the SQL generation prompt template (string with placeholders, or null for default)
        'sql_generation' => null,
        // Override the intent parsing prompt template
        'intent_parsing' => null,
        // Override the system role / preamble
        'system_role' => null,
    ],

    // ==========================================================================
    // RETRY / BACKOFF (network-level)
    // ==========================================================================
    // How the package waits before re-calling an LLM provider that returned a
    // transient failure (429 rate limit, 5xx, connection error). Waiting is
    // BLOCKING — the PHP worker is held — so all three caps below matter:
    // per-wait (max_delay_ms), cumulative (total_budget_ms), and attempt count
    // (each provider's own 'max_retries' above).
    //
    // Defaults keep the worst case under ~1s of waiting per API call. Raise
    // total_budget_ms only if you run on queues/CLI where blocking is cheap;
    // on PHP-FPM a long budget can exhaust the worker pool during a provider
    // outage, taking your whole site down with it.
    //
    // NOT retried: 4xx other than 429 (a bad key or malformed request fails
    // identically every time), and anything once the budget is spent.
    'retry' => [
        // Seconds advertised in the Retry-After header when an endpoint answers
        // 429. This is the signal to the CALLER — a browser, a queue worker, a
        // React app — about when to come back. The waits below are the
        // package's own internal retries against the provider, which happen
        // inside a single request and are a different thing entirely.
        'retry_after_seconds' => 60,

        // First wait, in milliseconds. Doubles each attempt (250 → 500 → 1000…)
        'base_delay_ms' => 250,
        // Ceiling for any single wait
        'max_delay_ms' => 2000,
        // Ceiling for ALL waits within one API call. When the next wait would
        // exceed it, the call fails immediately instead of half-waiting.
        // Set to 0 to disable the cumulative cap (not recommended on FPM).
        'total_budget_ms' => 4000,
        // Honour the provider's Retry-After header when present. It still has
        // to fit total_budget_ms — a "come back in 60s" hint fails fast.
        'respect_retry_after' => true,
        // Randomise each wait within [delay/2, delay] so concurrent workers
        // don't retry in lockstep and re-trigger the same rate limit.
        'jitter' => true,
    ],

    // ==========================================================================
    // ERROR HANDLING
    // ==========================================================================
    'errors' => [
        // Retry with refined prompt when AI fails to generate SQL
        'retry_on_failure' => true,
        // Maximum retry attempts (each adds more context to the prompt)
        'max_retries' => 2,
        // Show detailed error info (SQL, prompt length) in responses
        'verbose' => env('NATURALQUERY_VERBOSE_ERRORS', false),
    ],

    // ==========================================================================
    // SELF-VERIFICATION (X Factor)
    // ==========================================================================
    // AI verifies its own SQL before execution. Catches wrong columns,
    // wrong ORDER, wrong JOINs — before the user sees bad data.
    // Cost: ~200 tokens per verification (fraction of generation cost).
    // Skipped for cache hits and intent mode.
    'verification' => [
        'enabled' => env('NATURALQUERY_VERIFICATION_ENABLED', true),

        // Minimum confidence (0.0-1.0). Below this, AI attempts to fix the SQL.
        'confidence_threshold' => 0.7,

        // Max fix attempts when verification fails (0 = verify only, no fix)
        'max_fix_attempts' => 1,

        // Re-verify the fixed SQL? (adds another LLM call)
        'reverify_fixes' => false,

        // Skip verification for cached queries (already proven correct)
        'skip_on_cache_hit' => true,
    ],

    // ==========================================================================
    // SQL GENERATION
    // ==========================================================================
    'sql' => [
        // Default result limit when user doesn't specify
        // (e.g., "show me data" without saying "top 10")
        'default_limit' => 100,

        // Global maximum limit. Set to null for no global cap.
        // Individual schemas can override this with their own max_limit.
        // Example: 500 for a product catalogue, 50 for a dashboard, null for
        // unlimited. A cap keeps "list everything" from returning 200k rows.
        'max_limit' => null,

        // In 'auto' query mode, send a question straight to SQL generation when
        // its wording needs SQL the intent contract cannot express — a HAVING
        // ("customers with more than 10 orders"), a numeric filter ("orders
        // over 5000"), an exclusion ("excluding cancelled"), a ratio, a
        // DISTINCT, or a per-group top-N.
        //
        // Intent mode does not FAIL on these — it answers a narrower question
        // and says nothing about the part it dropped, which is the most
        // dangerous thing this package can do. Escalating costs nothing: intent
        // parsing and SQL generation are one API call each.
        //
        // Only affects 'auto'. An explicit query_mode of 'intent' is honoured
        // as written.
        'escalate_beyond_intent' => true,

        // Every dataset gets an implicit "record_count" metric (COUNT(*)), so
        // "how many orders by status" can be answered by counting rows rather
        // than by falling through to whichever measure is default and
        // reporting, say, revenue per status instead.
        //
        // A schema that declares its own 'count' or 'record_count' metric
        // always wins — set this to false only to remove the built-in entirely.
        'implicit_count_metric' => true,

        // SQL keywords that are NEVER allowed in generated queries
        'forbidden_keywords' => [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE',
            'EXEC', 'EXECUTE', 'GRANT', 'REVOKE', 'INTO',
            'COPY', 'pg_', 'information_schema', 'pg_catalog',
        ],

        // SQL operations allowed in generated queries
        'allowed_operations' => ['SELECT', 'WHERE', 'GROUP BY', 'ORDER BY', 'LIMIT', 'HAVING', 'WITH'],

        // Allow UNION ALL for service/category comparison queries
        'allow_union_all' => true,

        // Allow CTEs (WITH ... AS) for complex queries
        'allow_cte' => true,

        // Which Laravel database connection to use for executing queries
        // null = use default connection from config/database.php.
        //
        // Set this to point NaturalQuery at a different connection from the
        // rest of the app — a read replica, or a database it can introspect
        // when the app's own driver has no introspector.
        'database_connection' => null,

        // ADDITIONAL database drivers NaturalQuery can introspect, mapped to
        // the class that does it. sqlite, pgsql, mysql and mariadb are built
        // in (see Schema\IntrospectorRegistry) and need not be listed here.
        //
        // The built-ins live in code rather than in this file on purpose:
        // Laravel merges package config only one level deep, so an app that
        // published this file under an older version would never receive new
        // nested keys — and a missing driver map would break every query.
        //
        // To support another database, implement
        // Contracts\SchemaIntrospectorInterface and register it here:
        //
        //     'introspectors' => ['sqlsrv' => \App\Support\SqlServerIntrospector::class],
        //
        // Entries here override a built-in of the same name. Mapping a driver
        // to null removes it, so a built-in can be disabled as well as
        // replaced:
        //
        //     'introspectors' => ['sqlite' => null],
        'introspectors' => [],
    ],

    // ==========================================================================
    // SCHEMA CONFIGURATION
    // ==========================================================================
    'schema' => [
        // Directory containing per-schema configuration files.
        // Each .php file here defines one queryable dataset — this is how the
        // package adapts to ANY application: no code changes, just config.
        // Generate the files from your live database with:
        //     php artisan naturalquery:discover --ai
        // No limit on the number of schema files.
        'config_path' => config_path('naturalquery-schemas'),

        // Tables that naturalquery:discover skips. These are framework and
        // plumbing tables nobody asks business questions about — without this,
        // a first run buries the two tables you care about under migrations,
        // jobs and sessions. A trailing '*' matches a prefix.
        // Override with --all-tables, or edit this list for your app.
        'discover_exclude' => [
            'migrations',
            'password_resets',
            'password_reset_tokens',
            'failed_jobs',
            'jobs',
            'job_batches',
            'sessions',
            'cache',
            'cache_locks',
            'personal_access_tokens',
            'oauth_*',
            'telescope_*',
            'pulse_*',
            'naturalquery_*',
        ],
    ],

    // ==========================================================================
    // CHAT / MULTI-STEP
    // ==========================================================================
    // Features for conversational front-ends: questions that need more than one
    // query, and suggested follow-ups so a bot can offer the user somewhere to
    // go next.
    'chat' => [
        // Answer questions that need several queries — "revenue this year vs
        // last year" runs two and reports both plus the change.
        //
        // Costs ONE extra API call, and only for questions whose wording
        // suggests a comparison ("vs", "compare", "difference between", "year
        // on year"). An ordinary question never triggers planning and costs
        // exactly what it costs with this turned off.
        'multi_step' => env('NATURALQUERY_MULTI_STEP', true),

        // Hard ceiling on steps per question. Each step is a real query and a
        // real intent parse, so this bounds both latency and spend.
        'max_steps' => 4,

        // Suggested follow-up questions on every answer. Derived from your
        // schema — no API call, no added latency — and they can only suggest
        // breakdowns the validator would allow.
        'suggest_next_steps' => true,
        'max_next_steps' => 4,

        // Whether a suggestion may name a value from the results, e.g.
        // "Break West down by category" after West came top.
        //
        // These are built and returned locally like every other suggestion.
        // Clicking one sends that question to the provider as the user's own
        // query text — the same as typing it. Set false if you would rather no
        // value from your data ever reach a prompt, even by the user's hand.
        'suggest_drilldown_values' => true,
    ],

    // ==========================================================================
    // QUERY CACHE (Two-Tier)
    // ==========================================================================
    // Caches parsed intents to avoid repeated AI API calls for similar queries
    'cache' => [
        'enabled' => env('NATURALQUERY_CACHE_ENABLED', true),

        // Cache TTL in seconds (default: 24 hours)
        'ttl' => env('NATURALQUERY_CACHE_TTL', 86400),

        // Fuzzy match threshold (0.0 to 1.0) for reusing a cached intent.
        //
        // This is a LEXICAL comparison — shared words and edit distance. It
        // catches re-runs and near-identical wording ("top 5 customers" vs
        // "top five customers"), which is what it is for. It does NOT
        // understand meaning, so genuine paraphrases mostly miss the cache and
        // cost an API call.
        //
        // Do not lower this to try to catch paraphrases. Measured on this
        // implementation, "top 10 customers by revenue" and "bottom 10
        // customers by revenue" score 0.65 — higher than most real
        // paraphrases. Any threshold low enough to catch the paraphrases is
        // also low enough to answer a question with its opposite, from cache,
        // with no API call to correct it. Raise it if you want fewer reuses;
        // set cache.enabled to false if you want none.
        'similarity_threshold' => env('NATURALQUERY_CACHE_SIMILARITY', 0.85),

        // Tier 1 cache store (null = auto-detect from config/cache.php)
        'tier1_store' => env('NATURALQUERY_CACHE_STORE', null),

        // Cache key prefix for Tier 1
        'tier1_prefix' => 'naturalquery:',

        // Database table name for Tier 2 cache
        'table_name' => 'naturalquery_cache',
    ],

    // ==========================================================================
    // FEEDBACK & TRAINING
    // ==========================================================================
    // Stores user corrections so the AI learns from mistakes over time.
    // Corrections are included in future prompts as "past mistakes to avoid".
    'feedback' => [
        'enabled' => env('NATURALQUERY_FEEDBACK_ENABLED', true),
        'table_name' => 'naturalquery_feedback',
        // Max corrections to include per prompt (too many = slow/expensive)
        'max_per_prompt' => 5,
    ],

    // ==========================================================================
    // MULTI-TURN CONVERSATION
    // ==========================================================================
    // Enables follow-up queries: "top 5 customers by revenue" → "now just Europe"
    'conversation' => [
        'enabled' => env('NATURALQUERY_CONVERSATION_ENABLED', true),
        // How long conversation context is remembered (seconds)
        'ttl' => 1800, // 30 minutes

        // How many refinements may stack on one question before the user is
        // asked to start again.
        //
        // Ambiguity compounds. Past a handful of "only this", "and that",
        // nobody remembers which filters are still live, and resolution
        // degrades faster than anyone notices — so the honest move is to say
        // so rather than to keep resolving into something confident and wrong.
        // Every state is kept, so /conversation/{id}/rewind still steps back.
        //
        // 0 disables the cap.
        'max_refinements' => 6,
    ],

    // ==========================================================================
    // ROUTES
    // ==========================================================================
    // ==========================================================================
    // SPENDING LIMITS
    // ==========================================================================
    // Every question is a paid API call, so the routes above have a ceiling as
    // well as a rate. 'throttle:60,1' stops a burst; this stops a slow drain —
    // sixty a minute sustained is roughly 86,000 questions a day.
    'limits' => [
        // Questions per person per day. Counted per authenticated user, or per
        // IP when the routes are public. Set to null for no ceiling — a
        // deliberate choice rather than the default.
        //
        // 200/day is generous for a person and ruinous for a script.
        'queries_per_day' => env('NATURALQUERY_QUERIES_PER_DAY', 200),
    ],

    'routes' => [
        // Enable/disable package routes
        'enabled' => true,

        // URL prefix for all NaturalQuery routes
        'prefix' => 'naturalquery',

        // Middleware applied to all NaturalQuery routes
        // Includes throttle for rate limiting (60 requests/minute per user).
        //
        // Keep 'auth' unless you have thought hard about removing it. These
        // routes spend money on your API key: without authentication the widget
        // is an LLM proxy anyone can use, and limits.queries_per_day below is
        // then counted per IP, which is easy to get around.
        'middleware' => ['web', 'auth', 'throttle:60,1'],

        // Route name prefix
        'name_prefix' => 'naturalquery.',
    ],

    // ==========================================================================
    // PRIVACY & SECURITY
    // ==========================================================================
    'privacy' => [
        // Log all generated queries (query type only, never data values)
        'audit_queries' => true,

        // Specific log channel for audit (null = default Laravel log)
        'audit_channel' => null,

        // Remove API keys and URLs from error messages shown to users
        'sanitize_errors' => true,

        // Maximum allowed query length (characters)
        'max_query_length' => 1000,

        // ----------------------------------------------------------------
        // Optional companion package: jayanta/laravel-ai-guard
        // ----------------------------------------------------------------
        // Every question always passes through the built-in InputGuard
        // (prompt injection, SQL-in-text, exfiltration, resource abuse).
        // If ai-guard is ALSO installed it is detected automatically and adds
        // its scoring-based detector on top — no wiring required:
        //
        //     composer require jayanta/laravel-ai-guard
        //
        // When it is not installed these settings do nothing.
        'ai_guard' => [
            // false = ignore ai-guard even when it is installed.
            'enabled' => env('NATURALQUERY_AI_GUARD', true),

            // How an ai-guard detection is enforced here:
            //
            //   'auto'   — obey ai-guard's own config. It blocks only in its
            //              'block' mode, and only at/above its
            //              confidence_threshold. Its shipped default mode is
            //              'log_only', so out of the box a detection is logged
            //              and the built-in checks decide the outcome.
            //
            //   'always' — block on any ai-guard detection at/above its
            //              confidence_threshold, whatever mode ai-guard is in.
            //
            // Detections that do not block are logged at info level, so the
            // signal is never silently dropped.
            'enforce' => env('NATURALQUERY_AI_GUARD_ENFORCE', 'auto'),
        ],
    ],

    // ==========================================================================
    // RESPONSE FORMATTING
    // ==========================================================================
    'response' => [
        // Generate TTS-friendly text for voice output
        'include_speech_text' => true,

        // Generate statistical insights from results
        'include_insights' => true,

        // Include visualization type hint (bar, table, card, etc.)
        'include_visualization_hint' => true,

        // Number formatting style
        // 'international' = 1,234,567 | 'indian' = 12,34,567
        'number_format' => 'international',

        // Language/locale for voice TTS
        'locale' => 'en',
    ],

    // ==========================================================================
    // DROP-IN FRONTEND WIDGET
    // ==========================================================================
    // The package ships an embeddable text + voice widget. Add it to any Blade
    // view with ONE line:
    //
    //     <x-naturalquery::widget />
    //
    // The widget JS is served at {prefix}/widget.js (no publishing required).
    // Voice input uses the browser's speech recognition (works with EVERY LLM
    // provider); when unavailable it falls back to recording audio and sending
    // it to the /voice endpoint (requires a provider with voice support, e.g.
    // Gemini). Text input always works.
    'widget' => [
        // Widget header title
        'title' => env('NATURALQUERY_WIDGET_TITLE', 'Ask your data'),

        // Input placeholder text
        'placeholder' => 'Type a question or use the microphone…',

        // BCP-47 locale for speech recognition + text-to-speech
        // (e.g. en-US, en-GB, en-IN, hi-IN, de-DE).
        //
        // null follows the page's <html lang> and then the browser, which is
        // right for most apps. Set it only when your users speak a language
        // your markup does not declare.
        'language' => env('NATURALQUERY_WIDGET_LANGUAGE'),

        // Show the microphone button when the browser supports voice input
        'voice' => env('NATURALQUERY_WIDGET_VOICE', true),

        // Allow MediaRecorder → /voice fallback when browser STT is unavailable
        'server_voice' => true,

        // Offer text-to-speech readout of answers
        'tts' => env('NATURALQUERY_WIDGET_TTS', true),

        // Speak answers automatically (without pressing the speaker button)
        'auto_speak' => false,

        // Use the /conversation endpoint so follow-up questions keep context
        'conversation' => true,

        // Example query chips shown under the input (fill with real examples!)
        'examples' => [],

        // Primary color for buttons/bars
        'theme_color' => env('NATURALQUERY_WIDGET_THEME', '#2563eb'),

        // Small provenance note in the result footer
        'footer_note' => 'AI-generated · please verify important figures',

        // Enable the built-in demo page at {prefix}/demo.
        // Defaults to local environment only — NEVER enable blindly in
        // production; the demo page is unauthenticated by default route config.
        'demo_page' => env('NATURALQUERY_DEMO_PAGE', null) !== null
            ? (bool) env('NATURALQUERY_DEMO_PAGE')
            : null, // null = follow app environment (local only)
    ],

];
