<?php

namespace GregPriday\LaravelRetry;

use GregPriday\LaravelRetry\DeadLetterQueue\DatabaseDeadLetterQueueStorage;
use GregPriday\LaravelRetry\DeadLetterQueue\DeadLetterQueueHandler;
use GregPriday\LaravelRetry\DeadLetterQueue\DeadLetterQueueStorage;
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Http\HttpClientServiceProvider;
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

        // Register the DeadLetterQueueStorage implementation
        $this->app->bind(DeadLetterQueueStorage::class, function ($app) {
            return new DatabaseDeadLetterQueueStorage(
                table: config('retry.dead_letter.table'),
                connection: config('retry.dead_letter.connection')
            );
        });

        // Register the DeadLetterQueueHandler
        $this->app->singleton('retry.dead-letter-queue', function ($app) {
            return new DeadLetterQueueHandler(
                storage: $app->make(DeadLetterQueueStorage::class),
                shouldLog: config('retry.dead_letter.auto_log_failures', true),
                logLevel: config('retry.dead_letter.log_level', 'warning')
            );
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

            // Publish migrations
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'retry-migrations');
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
            DeadLetterQueueStorage::class,
            'retry',
            'retryable-pipeline',
            'retry.dead-letter-queue',
        ];
    }
}
