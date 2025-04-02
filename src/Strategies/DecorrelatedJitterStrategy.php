<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class DecorrelatedJitterStrategy implements RetryStrategy
{
    /**
     * Create a new decorrelated jitter strategy.
     *
     * Implementation based on AWS's "Exponential Backoff and Jitter" recommendations.
     *
     * @link https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/
     *
     * @param  float|null  $maxDelay  Maximum delay in seconds
     * @param  float  $minFactor  Minimum multiplier for the base delay
     * @param  float  $maxFactor  Maximum multiplier for the base delay
     */
    public function __construct(
        protected ?float $maxDelay = null,
        protected float $minFactor = 1.0,
        protected float $maxFactor = 3.0
    ) {}

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt, float $baseDelay): float
    {
        $minDelay = $baseDelay * $this->minFactor;
        $maxDelay = min(
            $this->maxDelay ?? PHP_FLOAT_MAX,
            $baseDelay * $this->maxFactor * (1 << $attempt)
        );

        // Generate a random delay between min and max
        $delay = mt_rand(
            (int) ($minDelay * 1000),
            (int) ($maxDelay * 1000)
        ) / 1000;

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
