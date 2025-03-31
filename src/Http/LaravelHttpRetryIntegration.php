<?php

namespace GregPriday\LaravelRetry\Http;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Strategies\GuzzleResponseStrategy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Integration with Laravel's HTTP client
 */
class LaravelHttpRetryIntegration
{
    /**
     * Register macros with Laravel's HTTP client
     */
    public static function register(): void
    {
        /**
         * Robust retry macro that uses Laravel Retry's strategies
         *
         * @param  int  $maxAttempts  Maximum number of retries
         * @param  RetryStrategy|null  $strategy  Retry strategy to use
         * @param  int  $retryDelay  Base delay in seconds
         * @param  int|null  $timeout  Timeout in seconds
         * @param  ExceptionHandlerManager|null  $exceptionManager  Custom exception manager
         * @param  bool  $throw  Whether to throw exceptions after all retries fail
         * @return PendingRequest
         */
        Http::macro('robustRetry', function (
            int $maxAttempts = 3,
            ?RetryStrategy $strategy = null,
            int $retryDelay = 1,
            ?int $timeout = null,
            ?ExceptionHandlerManager $exceptionManager = null,
            bool $throw = true
        ) {
            // Default to GuzzleResponseStrategy if no strategy provided
            $strategy ??= new GuzzleResponseStrategy;
            $pendingRequest = $this;

            // Determine if we should retry based on strategy
            $whenCallback = function (Throwable $exception, PendingRequest $request) use ($strategy, $maxAttempts) {
                static $currentAttempt = 0;
                $currentAttempt++;

                return $currentAttempt < $maxAttempts && $strategy->shouldRetry($currentAttempt - 1, $maxAttempts, $exception);
            };

            // Calculate delay using strategy
            $sleepCallback = function (int $attempt, Throwable $exception) use ($strategy, $retryDelay) {
                return $strategy->getDelay($attempt, $retryDelay) * 1000; // Convert to milliseconds for Laravel's HTTP client
            };

            // Apply timeout if provided
            if ($timeout !== null) {
                $pendingRequest = $pendingRequest->timeout($timeout);
            }

            // Apply retry using Laravel's built-in retry method
            return $pendingRequest->retry($maxAttempts, $sleepCallback, $whenCallback, $throw);
        });

        /**
         * Apply a specific retry strategy to the HTTP request
         *
         * @param  RetryStrategy  $strategy  The strategy to use for retries
         * @return PendingRequest
         */
        Http::macro('withRetryStrategy', function (RetryStrategy $strategy) {
            return $this->robustRetry(3, $strategy);
        });

        /**
         * Apply circuit breaker strategy to the HTTP request
         *
         * @param  int  $maxAttempts  Maximum number of retries
         * @param  int  $timeout  Circuit timeout in seconds
         * @return PendingRequest
         */
        Http::macro('withCircuitBreaker', function (int $maxAttempts = 3, int $timeout = 60) {
            $strategy = app(\GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy::class, [
                'timeout' => $timeout,
            ]);

            return $this->robustRetry($maxAttempts, $strategy);
        });

        /**
         * Apply rate limit detection and handling to the HTTP request
         *
         * @param  int  $maxAttempts  Maximum number of retries
         * @return PendingRequest
         */
        Http::macro('withRateLimitHandling', function (int $maxAttempts = 3) {
            $strategy = new GuzzleResponseStrategy;

            return $this->robustRetry($maxAttempts, $strategy);
        });
    }
}
