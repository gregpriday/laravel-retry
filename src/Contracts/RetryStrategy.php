<?php

namespace GregPriday\LaravelRetry\Contracts;

interface RetryStrategy
{
    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  float  $baseDelay  Base delay in seconds
     * @return int Delay in seconds
     */
    public function getDelay(int $attempt, float $baseDelay): int;

    /**
     * Determine if another retry attempt should be made.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  int  $maxAttempts  Maximum number of attempts allowed
     * @param  \Throwable|null  $lastException  The last exception that occurred
     */
    public function shouldRetry(int $attempt, int $maxAttempts, ?\Throwable $lastException = null): bool;
}
