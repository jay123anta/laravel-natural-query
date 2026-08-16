<?php

namespace Jayanta\NaturalQuery;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Contracts\SchemaIntrospectorInterface;
use Jayanta\NaturalQuery\Contracts\SqlValidatorInterface;
use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;
use Jayanta\NaturalQuery\LlmProviders\GeminiProvider;
use Jayanta\NaturalQuery\LlmProviders\OpenAiProvider;
use Jayanta\NaturalQuery\LlmProviders\ClaudeProvider;
use Jayanta\NaturalQuery\LlmProviders\OllamaProvider;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Schema\IntrospectorRegistry;
use Jayanta\NaturalQuery\Security\SqlValidator;
use Jayanta\NaturalQuery\Security\InputGuard;
use Jayanta\NaturalQuery\Cache\TwoTierQueryCache;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Engine\SqlBuilder;
use Jayanta\NaturalQuery\Engine\PromptBuilder;
use Jayanta\NaturalQuery\Engine\ResponseFormatter;
use Jayanta\NaturalQuery\Engine\QueryVerifier;
use Jayanta\NaturalQuery\Feedback\FeedbackStore;
use Jayanta\NaturalQuery\Conversation\ConversationManager;
use Jayanta\NaturalQuery\Console\InstallCommand;
use Jayanta\NaturalQuery\Console\DiscoverSchemaCommand;
use Jayanta\NaturalQuery\Console\AuditSchemaCommand;
use Jayanta\NaturalQuery\Console\CacheCleanupCommand;
use Jayanta\NaturalQuery\Console\CacheStatsCommand;
use Jayanta\NaturalQuery\Console\DebugPromptCommand;
use Jayanta\NaturalQuery\Console\DoctorCommand;

class NaturalQueryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/naturalquery.php', 'naturalquery');

        $this->app->singleton(SchemaRegistry::class, function ($app) {
            return new SchemaRegistry(
                config('naturalquery.schema.config_path', config_path('naturalquery-schemas'))
            );
        });

        $this->app->singleton(LlmProviderInterface::class, function ($app) {
            $driver = config('naturalquery.llm.driver', 'gemini');
            $providerConfig = config("naturalquery.llm.providers.{$driver}", []);

            return match ($driver) {
                'gemini' => new GeminiProvider($providerConfig),
                'openai' => new OpenAiProvider($providerConfig),
                'claude' => new ClaudeProvider($providerConfig),
                'ollama' => new OllamaProvider($providerConfig),
                // Any other driver name resolves through the OpenAI-compatible
                // provider when its config block declares a base_url. This is
                // how DeepSeek, Groq, Mistral, OpenRouter, vLLM, LM Studio,
                // LocalAI, llama.cpp server, etc. plug in — define a
                // providers.<name> block with base_url + model and set
                // NATURALQUERY_LLM_DRIVER=<name>. No code changes needed.
                default => !empty($providerConfig['base_url'])
                    ? new OpenAiProvider($providerConfig + ['name' => $driver])
                    : throw new \InvalidArgumentException(
                        "Unknown NaturalQuery LLM driver: '{$driver}'. Built-in: gemini, openai, claude, ollama. "
                        . "For any OpenAI-compatible service (DeepSeek, Groq, vLLM, LM Studio, …) add a "
                        . "'llm.providers.{$driver}' config block with 'base_url' and 'model'."
                    ),
            };
        });

        $this->app->singleton(SchemaIntrospectorInterface::class, function ($app) {
            $connection = config('naturalquery.sql.database_connection') ?? config('database.default');
            $driver = config("database.connections.{$connection}.driver", 'mysql');

            if ($class = IntrospectorRegistry::classFor($driver)) {
                return $app->make($class);
            }

            // A bare "unsupported driver" inside a 500 page tells a novice
            // nothing, so name the driver and the way out.
            throw new \InvalidArgumentException(
                "NaturalQuery cannot introspect the '{$driver}' database driver. "
                . 'Supported: ' . implode(', ', IntrospectorRegistry::supportedDrivers())
                . ". You can add your own by mapping the driver to a class implementing "
                . "SchemaIntrospectorInterface under 'sql.introspectors' in config/naturalquery.php"
                . ". Run 'php artisan naturalquery:doctor' for a full checkup."
            );
        });

        $this->app->singleton(SqlValidatorInterface::class, SqlValidator::class);
        $this->app->singleton(InputGuard::class);
        $this->app->singleton(FeedbackStore::class);

        $this->app->singleton(QueryCacheInterface::class, function ($app) {
            if (!config('naturalquery.cache.enabled', true)) {
                return new \Jayanta\NaturalQuery\Cache\NullQueryCache();
            }
            return $app->make(TwoTierQueryCache::class);
        });

        $this->app->singleton(SqlBuilder::class);
        $this->app->singleton(PromptBuilder::class);
        $this->app->singleton(ResponseFormatter::class);
        $this->app->singleton(QueryVerifier::class);
        $this->app->singleton(\Jayanta\NaturalQuery\Engine\QueryPlanner::class);
        $this->app->singleton(\Jayanta\NaturalQuery\Engine\StepSynthesizer::class);
        $this->app->singleton(\Jayanta\NaturalQuery\Engine\NextStepSuggester::class);
        $this->app->singleton(\Jayanta\NaturalQuery\Engine\IntentCoverage::class);

        // DatasetSeeder needs only SchemaRegistry, already bound above, so the
        // container auto-wires it — used both for dataset detection and, via
        // QueryOrchestrator::resolveAskingDataset(), to tell a genuine cache
        // hit from a cross-dataset miss (NQ-003-FIX: a mismatch misses, it is
        // never silently reconciled). PromptBudget takes a scalar
        // (prompts.max_chars, null = unbounded, C1) that the container cannot
        // infer, so it gets an explicit factory. Both are STATELESS —
        // singletons are safe only because detect()/check() never memoise
        // anything on $this, which is what lets a step of a multi-step
        // answer call them again cleanly.
        $this->app->singleton(\Jayanta\NaturalQuery\Engine\DatasetSeeder::class);
        $this->app->singleton(\Jayanta\NaturalQuery\Engine\PromptBudget::class, function ($app) {
            // The env var is consulted directly when the published config has
            // no such key. mergeConfigFrom is ONE LEVEL deep, so an app that
            // ran naturalquery:install before 2.1.0 has a published `prompts`
            // array that replaces the package's wholesale — and `max_chars`,
            // being new, simply does not exist in it. Reading only through
            // config() meant the setting was unreachable on exactly the
            // installs most likely to want it, with no error: you set
            // NATURALQUERY_PROMPT_MAX_CHARS, nothing happened, and nothing
            // said why.
            //
            // array_key_exists, not ??. A published config that sets max_chars
            // to null is saying "unbounded" and must win; one that has no such
            // key has not spoken, and that is the case the fallback is for.
            // `??` cannot tell those apart and would override the first.
            $prompts = config('naturalquery.prompts', []);

            $max = array_key_exists('max_chars', $prompts)
                ? $prompts['max_chars']
                : \Jayanta\NaturalQuery\Support\EnvValue::int('NATURALQUERY_PROMPT_MAX_CHARS', null);

            return new \Jayanta\NaturalQuery\Engine\PromptBudget($max);
        });

        $this->app->singleton(QueryOrchestrator::class);
        $this->app->singleton(ConversationManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/naturalquery.php' => config_path('naturalquery.php'),
        ], 'naturalquery-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'naturalquery-migrations');

        // Frontend widget: publishable copy for apps that bundle their own
        // assets. Publishing is OPTIONAL — the package also serves the widget
        // directly at {prefix}/widget.js (see routes/api.php).
        $this->publishes([
            __DIR__ . '/../resources/js/naturalquery-widget.js' => public_path('vendor/naturalquery/naturalquery-widget.js'),
        ], 'naturalquery-assets');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Views + <x-naturalquery::widget /> Blade component
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'naturalquery');
        \Illuminate\Support\Facades\Blade::anonymousComponentPath(
            __DIR__ . '/../resources/views/components',
            'naturalquery'
        );

        if (config('naturalquery.routes.enabled', true)) {
            $this->registerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                DiscoverSchemaCommand::class,
                AuditSchemaCommand::class,
                CacheCleanupCommand::class,
                CacheStatsCommand::class,
                DebugPromptCommand::class,
                DoctorCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        $middleware = config('naturalquery.routes.middleware', ['web', 'throttle:60,1']);

        // Both appended rather than left to the config array. An app that
        // customises routes.middleware — the first thing anyone does to make
        // the widget public — would otherwise drop the authorisation check and
        // the spending ceiling without meaning to, at exactly the moment they
        // start to matter.
        //
        // Authorize first: a refused request should not count against the
        // day's budget.
        $middleware[] = \Jayanta\NaturalQuery\Http\Middleware\Authorize::class;
        $middleware[] = \Jayanta\NaturalQuery\Http\Middleware\EnforceQueryBudget::class;

        Route::prefix(config('naturalquery.routes.prefix', 'naturalquery'))
            ->middleware($middleware)
            ->name(config('naturalquery.routes.name_prefix', 'naturalquery.'))
            ->group(__DIR__ . '/../routes/api.php');
    }

}
