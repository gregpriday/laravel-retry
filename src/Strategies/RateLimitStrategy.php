<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class RateLimitStrategy implements RetryStrategy
{
    /**
     * Create a new rate limit strategy.
     *
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @param  RetryStrategy  $innerStrategy  The wrapped retry strategy
     * @param  int  $maxAttempts  Maximum attempts per time window
     * @param  int  $timeWindow  Time window in seconds
     * @param  string  $storageKey  Unique key for this rate limiter instance
     */
    public function __construct(
        protected float $baseDelay,
        protected RetryStrategy $innerStrategy,
        protected int $maxAttempts = 100,
        protected int $timeWindow = 60,
        protected string $storageKey = 'default'
    ) {
        // We'll use Laravel's RateLimiter instead of static storage
    }

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt): float
    {
        $innerDelay = $this->innerStrategy->getDelay($attempt);

        // Add additional delay if we're near the rate limit
        $currentRate = $this->getCurrentRate();
        if ($currentRate >= $this->maxAttempts * 0.8) {
            // Calculate additional delay based on how close we are to the limit
            $usageRatio = $currentRate / $this->maxAttempts;
            $additionalDelay = $usageRatio * $this->timeWindow * 0.1;
            $innerDelay += $additionalDelay;
        }

        // If rate limited, ensure the delay is at least the time until reset
        $availableIn = RateLimiter::availableIn($this->storageKey);
        if ($availableIn > 0) {
            return max($innerDelay, (float) $availableIn);
        }

        return $innerDelay;
    }

    /**
     * Determine if another retry attempt should be made.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  int  $maxAttempts  Maximum number of attempts allowed
     * @param  Throwable|null  $lastException  The last exception that occurred
     */
    public function shouldRetry(int $attempt, int $maxAttempts, ?Throwable $lastException = null): bool
    {
        if (! $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException)) {
            return false;
        }

        // If max attempts is 0, never allow retries
        if ($this->maxAttempts <= 0) {
            return false;
        }

        // Check rate limit using Laravel's RateLimiter
        if (RateLimiter::tooManyAttempts($this->storageKey, $this->maxAttempts)) {
            return false;
        }

        // Record the attempt using Laravel's RateLimiter
        RateLimiter::hit($this->storageKey, $this->timeWindow);

        return true;
    }

    /**
     * Get the current rate of attempts within the time window.
     */
    protected function getCurrentRate(): int
    {
        return $this->maxAttempts - $this->getRemainingAttempts();
    }

    /**
     * Get the remaining attempts allowed in the current time window.
     */
    public function getRemainingAttempts(): int
    {
        return RateLimiter::remaining($this->storageKey, $this->maxAttempts);
    }

    /**
     * Get the time until the rate limit resets.
     */
    public function getTimeUntilReset(): int
    {
        if (RateLimiter::tooManyAttempts($this->storageKey, $this->maxAttempts)) {
            return RateLimiter::availableIn($this->storageKey);
        }

        return 0;
    }

    /**
     * Reset the rate limiter for this storage key.
     */
    public function reset(): void
    {
        RateLimiter::clear($this->storageKey);
    }

    /**
     * Reset all rate limiters across all storage keys.
     *
     * Note: This is not possible with Laravel's RateLimiter in the same way as the static storage.
     * It would require knowledge of all keys used, which isn't tracked. This method is kept
     * for API compatibility but will only reset the default key.
     */
    public static function resetAll(): void
    {
        // Since we can't know all keys used with Laravel's RateLimiter, we can only reset
        // the default key for backward compatibility
        RateLimiter::clear('default');
    }

    /**
     * Get the current rate limit configuration and status.
     *
     * @return array{
     *     max_attempts: int,
     *     time_window: int,
     *     remaining: int,
     *     reset_in: int,
     *     current_rate: int,
     *     storage_key: string
     * }
     */
    public function getRateLimitInfo(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'time_window'  => $this->timeWindow,
            'remaining'    => $this->getRemainingAttempts(),
            'reset_in'     => $this->getTimeUntilReset(),
            'current_rate' => $this->getCurrentRate(),
            'storage_key'  => $this->storageKey,
        ];
    }
}
