<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

/**
 * ExponentialBackoffStrategy increases the delay between retries exponentially.
 *
 * This strategy is the default for most retry scenarios, providing gradually increasing
 * delays (e.g., 1s, 2s, 4s, 8s) to prevent overwhelming services during recovery.
 * It supports optional jitter to prevent the "thundering herd" problem.
 */
class ExponentialBackoffStrategy implements RetryStrategy
{
    /**
     * Create a new exponential backoff strategy.
     *
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @param  float  $multiplier  The multiplier for each subsequent retry
     * @param  float|null  $maxDelay  Maximum delay in seconds
     * @param  bool  $withJitter  Whether to add random jitter to delays
     * @param  float  $jitterPercent  The percentage of jitter to apply (0.2 means ±20%)
     */
    public function __construct(
        protected float $baseDelay = 1.0,
        protected float $multiplier = 2.0,
        protected ?float $maxDelay = null,
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
        // Calculate exponential delay: baseDelay * multiplier^attempt
        $delay = $this->baseDelay * pow($this->multiplier, $attempt);

        if ($this->withJitter) {
            $delay = $this->addJitter($delay);
        }

        if ($this->maxDelay !== null) {
            $delay = min($delay, $this->maxDelay);
        }

        return max(0.0, $delay);
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
