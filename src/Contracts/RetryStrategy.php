<?php

namespace GregPriday\LaravelRetry\Contracts;

use Throwable;

interface RetryStrategy
{
    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt, float $baseDelay): float;

    /**
     * Determine if another retry attempt should be made.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  int  $maxAttempts  Maximum number of attempts allowed
     * @param  Throwable|null  $lastException  The last exception that occurred
     */
    public function shouldRetry(int $attempt, int $maxAttempts, ?Throwable $lastException = null): bool;
}
