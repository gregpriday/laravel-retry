<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class TotalTimeoutStrategy implements RetryStrategy
{
    /**
     * The time when the operation started.
     */
    protected float $startTime;

    /**
     * Create a new total timeout strategy.
     *
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @param  RetryStrategy  $innerStrategy  The wrapped retry strategy
     * @param  float  $totalTimeout  Total operation timeout in seconds
     */
    public function __construct(
        protected float $baseDelay,
        protected RetryStrategy $innerStrategy,
        protected float $totalTimeout
    ) {
        $this->startTime = microtime(true);
    }

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt): float
    {
        // Get the requested delay from the inner strategy
        $requestedDelay = $this->innerStrategy->getDelay($attempt);

        // Calculate how much time has elapsed since the start
        $elapsedTime = microtime(true) - $this->startTime;

        // Calculate how much time remains before we hit the total timeout
        $remainingTime = $this->totalTimeout - $elapsedTime;

        // If we have less time remaining than the requested delay, adjust the delay
        if ($remainingTime <= 0) {
            // No time left, don't delay at all (though we probably won't retry)
            return 0.0;
        } elseif ($remainingTime < $requestedDelay) {
            // Use the remaining time as the delay (leave a small buffer of 0.1s
            // to account for execution overhead and prevent potentially exceeding the timeout)
            return max(0.0, $remainingTime - 0.1);
        }

        // Otherwise, use the delay requested by the inner strategy
        return $requestedDelay;
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
        // Check if we've exceeded the total timeout
        $elapsedTime = microtime(true) - $this->startTime;
        if ($elapsedTime >= $this->totalTimeout) {
            return false;
        }

        // Defer to the inner strategy for its opinion
        return $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException);
    }

    /**
     * Get the inner strategy.
     */
    public function getInnerStrategy(): RetryStrategy
    {
        return $this->innerStrategy;
    }

    /**
     * Get the total timeout in seconds.
     */
    public function getTotalTimeout(): float
    {
        return $this->totalTimeout;
    }

    /**
     * Get elapsed time since operation started.
     *
     * @return float Elapsed time in seconds
     */
    public function getElapsedTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Reset the start time to the current time.
     */
    public function resetStartTime(): self
    {
        $this->startTime = microtime(true);

        return $this;
    }
}
