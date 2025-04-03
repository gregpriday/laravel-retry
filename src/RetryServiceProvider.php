<?php

namespace GregPriday\LaravelRetry;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Http\HttpClientServiceProvider;
use GregPriday\LaravelRetry\Pipeline\RetryablePipeline;
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Utils\StrategyHelper;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use ReflectionException;

class RetryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/retry.php',
            'retry'
        );

        // Register the ExceptionHandlerManager as a singleton
        $this->app->singleton(ExceptionHandlerManager::class, function ($app) {
            $manager = new ExceptionHandlerManager;
            $manager->registerDefaultHandlers();

            return $manager;
        });

        // Register the Retry class as a singleton
        $this->app->singleton(Retry::class, function ($app) {
            // Get the default strategy from config
            $defaultStrategy = config('retry.default', 'exponential-backoff');

            // Get the strategy options from the strategies config section
            $strategyOptions = config("retry.strategies.{$defaultStrategy}", []);

            // Create the strategy instance using the factory
            $strategy = $this->createStrategyInstance($defaultStrategy, $strategyOptions);

            return new Retry(
                maxRetries: config('retry.max_retries'),
                timeout: config('retry.timeout'),
                strategy: $strategy,
                exceptionManager: $app->make(ExceptionHandlerManager::class)
            );
        });

        // Register the RetryablePipeline
        $this->app->bind(RetryablePipeline::class, function ($app) {
            return new RetryablePipeline($app);
        });

        // Register the facade accessor
        $this->app->alias(Retry::class, 'retry');
        $this->app->alias(RetryablePipeline::class, 'retryable-pipeline');

        // Register a strategy factory that can be used throughout the application
        $this->app->singleton('retry.strategy.factory', function (Application $app) {
            return new class($app)
            {
                protected $app;

                public function __construct(Application $app)
                {
                    $this->app = $app;
                }

                /**
                 * Create a strategy instance with the specified parameters.
                 *
                 * @param  string  $strategyIdentifier  The class name or kebab-case alias of the strategy
                 * @param  array  $options  Options for the strategy constructor
                 *
                 * @throws LogicException
                 */
                public function create(string $strategyIdentifier, array $options = []): RetryStrategy
                {
                    $strategyClass = $strategyIdentifier;

                    // Check if it looks like a kebab-case alias (contains hyphens, lowercase)
                    if (str_contains($strategyIdentifier, '-') && Str::lower($strategyIdentifier) === $strategyIdentifier) {
                        // Convert kebab-case alias to class name
                        $strategyClass = StrategyHelper::aliasToClass($strategyIdentifier);

                        if ($strategyClass === null) {
                            throw new LogicException("Invalid strategy alias '{$strategyIdentifier}'");
                        }

                        // If no options were provided and this is an alias, attempt to get defaults from config
                        if (empty($options)) {
                            $options = config("retry.strategies.{$strategyIdentifier}", []);
                        }
                    } elseif (! class_exists($strategyIdentifier)) {
                        throw new LogicException("Strategy class '{$strategyIdentifier}' does not exist");
                    }

                    try {
                        // Create a reflection class
                        $reflection = new ReflectionClass($strategyClass);

                        // Ensure we're instantiating a RetryStrategy
                        if (! $reflection->implementsInterface(RetryStrategy::class)) {
                            throw new LogicException("'{$strategyClass}' is not a valid RetryStrategy");
                        }

                        // Create and return the instance with the options
                        return $this->app->make($strategyClass, $options);
                    } catch (ReflectionException $e) {
                        // Fallback to default strategy on error
                        $this->app->make('log')->error("Failed to instantiate retry strategy '{$strategyClass}': {$e->getMessage()}");

                        return new ExponentialBackoffStrategy;
                    }
                }
            };
        });

        // Register the HTTP client integration
        $this->app->register(HttpClientServiceProvider::class);

        // Register factory for circuit breakers based on config
        $this->app->singleton('retry.circuit_breaker.factory', function (Application $app) {
            return new class($app)
            {
                protected $app;

                public function __construct(Application $app)
                {
                    $this->app = $app;
                }

                /**
                 * Create a circuit breaker for the given service.
                 */
                public function create(?string $service = null): CircuitBreakerStrategy
                {
                    $config = config('retry.circuit_breaker');

                    // Get configuration settings for the requested service or default
                    $settings = $service && isset($config['services'][$service])
                        ? array_merge($config['default'], $config['services'][$service])
                        : $config['default'];

                    // Get the inner strategy alias from settings
                    $innerStrategyAlias = $settings['inner_strategy'] ?? config('retry.default', 'exponential-backoff');

                    // Get the options for this inner strategy from the strategies section
                    $innerOptions = config("retry.strategies.{$innerStrategyAlias}", []);

                    // If inner_config is explicitly set in the circuit breaker settings, use it
                    if (isset($settings['inner_config']) && is_array($settings['inner_config'])) {
                        $innerOptions = array_merge($innerOptions, $settings['inner_config']);
                    }

                    // Create the inner strategy
                    $innerStrategy = $this->app->make('retry.strategy.factory')->create(
                        $innerStrategyAlias,
                        $innerOptions
                    );

                    // Create and return the circuit breaker
                    return new CircuitBreakerStrategy(
                        $innerStrategy,
                        $settings['failure_threshold'],
                        $settings['reset_timeout'],
                        $settings['cache_key'] ?? ($service ? "circuit_breaker_{$service}" : null),
                        $settings['cache_ttl'],
                        $settings['fail_open_on_cache_error']
                    );
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/retry.php' => config_path('retry.php'),
            ], 'retry-config');

            $this->publishes([
                __DIR__.'/Exceptions/Handlers' => app_path('Exceptions/Retry/Handlers'),
            ], 'retry-handlers');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            Retry::class,
            ExceptionHandlerManager::class,
            RetryablePipeline::class,
            'retry',
            'retryable-pipeline',
            'retry.strategy.factory',
            'retry.circuit_breaker.factory',
        ];
    }

    /**
     * Create a strategy instance with the specified parameters.
     *
     * @param  string  $strategyIdentifier  The class name or kebab-case alias of the strategy
     * @param  array  $options  Options for the strategy constructor
     * @return \GregPriday\LaravelRetry\Contracts\RetryStrategy
     *
     * @throws LogicException
     */
    protected function createStrategyInstance(string $strategyIdentifier, array $options = [])
    {
        return $this->app->make('retry.strategy.factory')->create($strategyIdentifier, $options);
    }
}
