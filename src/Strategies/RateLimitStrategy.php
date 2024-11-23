<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RateLimitStrategy implements RetryStrategy
{
    /**
     * Create a new rate limit strategy.
     *
     * @param RetryStrategy $innerStrategy The wrapped retry strategy
     * @param int $maxAttempts Maximum attempts per time window
     * @param int $timeWindow Time window in seconds
     * @param string $cachePrefix Prefix for cache keys
     */
    public function __construct(
        protected RetryStrategy $innerStrategy,
        protected int $maxAttempts = 100,
        protected int $timeWindow = 60,
        protected string $cachePrefix = 'rate_limit'
    ) {}

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param int $attempt Current attempt number (0-based)
     * @param float $baseDelay Base delay in seconds
     * @return int Delay in seconds
     */
    public function getDelay(int $attempt, float $baseDelay): int
    {
        $baseDelay = $this->innerStrategy->getDelay($attempt, $baseDelay);

        // Add additional delay if we're near the rate limit
        $currentRate = $this->getCurrentRate();
        if ($currentRate >= $this->maxAttempts * 0.8) {
            // Calculate additional delay based on how close we are to the limit
            $usageRatio = $currentRate / $this->maxAttempts;
            $additionalDelay = (int) ceil($usageRatio * $this->timeWindow * 0.1);
            $baseDelay += $additionalDelay;
        }

        return $baseDelay;
    }

    /**
     * Determine if another retry attempt should be made.
     *
     * @param int $attempt Current attempt number (0-based)
     * @param int $maxAttempts Maximum number of attempts allowed
     * @param Throwable|null $lastException The last exception that occurred
     * @return bool
     */
    public function shouldRetry(int $attempt, int $maxAttempts, ?Throwable $lastException = null): bool
    {
        if (!$this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException)) {
            return false;
        }

        $currentRate = $this->getCurrentRate();
        if ($currentRate >= $this->maxAttempts) {
            return false;
        }

        $this->recordAttempt();
        return true;
    }

    /**
     * Get the current rate of attempts.
     */
    protected function getCurrentRate(): int
    {
        $this->cleanupOldAttempts();
        return count($this->getAttempts());
    }

    /**
     * Record a new attempt.
     */
    protected function recordAttempt(): void
    {
        $attempts = $this->getAttempts();
        $attempts[] = time();
        Cache::put($this->getCacheKey(), $attempts, $this->timeWindow);
    }

    /**
     * Get all attempts within the current time window.
     *
     * @return array<int>
     */
    protected function getAttempts(): array
    {
        return Cache::get($this->getCacheKey(), []);
    }

    /**
     * Remove attempts outside the current time window.
     */
    protected function cleanupOldAttempts(): void
    {
        $attempts = $this->getAttempts();
        $cutoff = time() - $this->timeWindow;

        $validAttempts = array_filter(
            $attempts,
            fn(int $timestamp) => $timestamp > $cutoff
        );

        if (count($validAttempts) !== count($attempts)) {
            Cache::put($this->getCacheKey(), array_values($validAttempts), $this->timeWindow);
        }
    }

    /**
     * Get the cache key for storing attempts.
     */
    protected function getCacheKey(): string
    {
        return "{$this->cachePrefix}_attempts";
    }

    /**
     * Get the remaining attempts allowed in the current time window.
     */
    public function getRemainingAttempts(): int
    {
        return max(0, $this->maxAttempts - $this->getCurrentRate());
    }

    /**
     * Get the time until the rate limit resets.
     */
    public function getTimeUntilReset(): int
    {
        $attempts = $this->getAttempts();
        if (empty($attempts)) {
            return 0;
        }

        $oldestAttempt = min($attempts);
        return max(0, ($oldestAttempt + $this->timeWindow) - time());
    }

    /**
     * Reset the rate limiter.
     */
    public function reset(): void
    {
        Cache::forget($this->getCacheKey());
    }

    /**
     * Get the current rate limit configuration.
     *
     * @return array{
     *     max_attempts: int,
     *     time_window: int,
     *     remaining: int,
     *     reset_in: int
     * }
     */
    public function getRateLimitInfo(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'time_window' => $this->timeWindow,
            'remaining' => $this->getRemainingAttempts(),
            'reset_in' => $this->getTimeUntilReset()
        ];
    }
}