<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

/**
 * FixedDelayStrategy uses the same delay duration for every retry attempt.
 *
 * This strategy provides predictable, consistent delays between retries.
 * It's useful when the expected recovery time is consistent, or when
 * predictable timing is required. Supports optional jitter.
 */
class FixedDelayStrategy implements RetryStrategy
{
    /**
     * Create a new fixed delay strategy.
     *
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @param  bool  $withJitter  Whether to add random jitter to delays
     * @param  float  $jitterPercent  The percentage of jitter to apply (0.2 means ±20%)
     */
    public function __construct(
        protected float $baseDelay = 1.0,
        protected bool $withJitter = false,
        protected float $jitterPercent = 0.2
    ) {}

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt): float
    {
        if (! $this->withJitter) {
            return $this->baseDelay;
        }

        return $this->addJitter($this->baseDelay);
    }

    /**
     * Add random jitter to the delay.
     *
     * @param  float  $delay  Base delay value
     * @return float Delay with jitter
     */
    protected function addJitter(float $delay): float
    {
        // Add jitter based on the configured percentage
        $jitterRange = $delay * $this->jitterPercent;
        $jitter = mt_rand(-100, 100) / 100 * $jitterRange;

        return max(0.0, $delay + $jitter);
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
        return $attempt < $maxAttempts;
    }
}
