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
use Jayanta\NaturalQuery\Console\CacheCleanupCommand;
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

            // Laravel 11 and 12 ship with DB_CONNECTION=sqlite by default, so
            // this is the first thing many adopters hit. Say what is wrong and
            // exactly how to fix it — a bare "unsupported driver" inside a 500
            // page tells a novice nothing.
            throw new \InvalidArgumentException(
                "NaturalQuery cannot introspect the '{$driver}' database driver. "
                . 'Supported: ' . implode(', ', IntrospectorRegistry::supportedDrivers())
                . ($driver === 'sqlite'
                    ? ". Laravel 11+ defaults to SQLite — either set DB_CONNECTION to mysql or pgsql "
                      . "in .env, or keep your app on SQLite and point only NaturalQuery elsewhere via "
                      . "'sql.database_connection' in config/naturalquery.php"
                    : '')
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
                CacheCleanupCommand::class,
                DebugPromptCommand::class,
                DoctorCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        Route::prefix(config('naturalquery.routes.prefix', 'naturalquery'))
            ->middleware(config('naturalquery.routes.middleware', ['web', 'auth', 'throttle:60,1']))
            ->name(config('naturalquery.routes.name_prefix', 'naturalquery.'))
            ->group(__DIR__ . '/../routes/api.php');
    }
}
