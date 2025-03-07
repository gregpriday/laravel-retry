<?php

namespace GregPriday\LaravelRetry;

use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Pipeline\RetryablePipeline;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use Illuminate\Support\ServiceProvider;

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
            return new Retry(
                maxRetries: config('retry.max_retries'),
                retryDelay: config('retry.delay'),
                timeout: config('retry.timeout'),
                strategy: new ExponentialBackoffStrategy,
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/retry.php' => config_path('retry.php'),
                __DIR__.'/Exceptions/Handlers' => app_path('Exceptions/Retry/Handlers'),
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
}
