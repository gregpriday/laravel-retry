<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class LinearBackoffStrategy implements RetryStrategy
{
    /**
     * Create a new linear backoff strategy.
     *
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @param  float  $increment  The increment for each subsequent retry
     * @param  float|null  $maxDelay  Maximum delay in seconds
     */
    public function __construct(
        protected float $baseDelay = 1.0,
        protected float $increment = 1.0,
        protected ?float $maxDelay = null
    ) {}

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt): float
    {
        // Calculate linear delay: baseDelay + (increment * attempt)
        $delay = $this->baseDelay + ($this->increment * $attempt);

        if ($this->maxDelay !== null) {
            $delay = min($delay, $this->maxDelay);
        }

        return max(0.0, $delay);
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
