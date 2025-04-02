<?php

namespace GregPriday\LaravelRetry;

use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Http\HttpClientServiceProvider;
use GregPriday\LaravelRetry\Pipeline\RetryablePipeline;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use Illuminate\Support\ServiceProvider;
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
            // Get the default strategy configuration
            $strategyConfig = config('retry.default_strategy', []);
            $strategyClass = $strategyConfig['class'] ?? ExponentialBackoffStrategy::class;
            $strategyOptions = $strategyConfig['options'] ?? [];

            // Legacy support: If baseDelay isn't explicitly set in options,
            // check for the old config key.
            if (! isset($strategyOptions['baseDelay']) && config()->has('retry.delay')) {
                // Log a deprecation warning if possible
                if (function_exists('logger')) {
                    logger()->warning("Using deprecated 'retry.delay' config. Please define 'baseDelay' within 'retry.default_strategy.options' instead.");
                }
                $strategyOptions['baseDelay'] = (float) config('retry.delay');
            }

            // Create the strategy instance
            $strategy = $this->createStrategyInstance($strategyClass, $strategyOptions);

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

        // Register the HTTP client integration
        $this->app->register(HttpClientServiceProvider::class);
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
        ];
    }

    /**
     * Create a strategy instance with the specified parameters.
     *
     * @param  string  $strategyClass  The class name of the strategy
     * @param  array  $options  Options for the strategy constructor
     * @return \GregPriday\LaravelRetry\Contracts\RetryStrategy
     *
     * @throws LogicException
     */
    protected function createStrategyInstance(string $strategyClass, array $options = [])
    {
        try {
            // Check if the class exists and is a RetryStrategy
            if (! class_exists($strategyClass)) {
                throw new LogicException("Strategy class '{$strategyClass}' does not exist");
            }

            // Create a reflection class
            $reflection = new ReflectionClass($strategyClass);

            // Ensure we're instantiating a RetryStrategy
            if (! $reflection->implementsInterface('GregPriday\LaravelRetry\Contracts\RetryStrategy')) {
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
}
