<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class RateLimitStrategy implements RetryStrategy
{
    /**
     * Internal storage for attempts across instances
     *
     * @var array<string, array<array{timestamp: int, window_end: int}>>
     */
    private static array $attemptStorage = [];

    /**
     * Create a new rate limit strategy.
     *
     * @param RetryStrategy $innerStrategy The wrapped retry strategy
     * @param int $maxAttempts Maximum attempts per time window
     * @param int $timeWindow Time window in seconds
     * @param string $storageKey Unique key for this rate limiter instance
     */
    public function __construct(
        protected RetryStrategy $innerStrategy,
        protected int $maxAttempts = 100,
        protected int $timeWindow = 60,
        protected string $storageKey = 'default'
    ) {
        // Initialize storage for this key if it doesn't exist
        if (!isset(self::$attemptStorage[$this->storageKey])) {
            self::$attemptStorage[$this->storageKey] = [];
        }
    }

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
     * Get the current rate of attempts within the time window.
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
        self::$attemptStorage[$this->storageKey][] = [
            'timestamp' => time(),
            'window_end' => time() + $this->timeWindow
        ];
    }

    /**
     * Get all valid attempts within the current time window.
     *
     * @return array<array{timestamp: int, window_end: int}>
     */
    protected function getAttempts(): array
    {
        return self::$attemptStorage[$this->storageKey] ?? [];
    }

    /**
     * Remove attempts outside the current time window.
     */
    protected function cleanupOldAttempts(): void
    {
        $currentTime = time();
        self::$attemptStorage[$this->storageKey] = array_values(
            array_filter(
                self::$attemptStorage[$this->storageKey] ?? [],
                fn(array $attempt) => $attempt['window_end'] > $currentTime
            )
        );
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

        $currentTime = time();
        $nextReset = min(
            array_map(
                fn(array $attempt) => $attempt['window_end'],
                $attempts
            )
        );

        return max(0, $nextReset - $currentTime);
    }

    /**
     * Reset the rate limiter for this storage key.
     */
    public function reset(): void
    {
        self::$attemptStorage[$this->storageKey] = [];
    }

    /**
     * Reset all rate limiters across all storage keys.
     */
    public static function resetAll(): void
    {
        self::$attemptStorage = [];
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
            'time_window' => $this->timeWindow,
            'remaining' => $this->getRemainingAttempts(),
            'reset_in' => $this->getTimeUntilReset(),
            'current_rate' => $this->getCurrentRate(),
            'storage_key' => $this->storageKey
        ];
    }
}