<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class FixedDelayStrategy implements RetryStrategy
{
    /**
     * Create a new fixed delay strategy.
     */
    public function __construct(
        protected bool $withJitter = false,
        protected float $jitterPercent = 0.2
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
        if (!$this->withJitter) {
            return (int) ceil($baseDelay);
        }

        // Add jitter if enabled
        $jitterRange = $baseDelay * $this->jitterPercent;
        $jitter = mt_rand(-100, 100) / 100 * $jitterRange;

        return (int) ceil($baseDelay + $jitter);
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
        return $attempt < $maxAttempts;
    }
}