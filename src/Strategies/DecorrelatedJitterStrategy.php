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
     * @link https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/
     *
     * @param int|null $maxDelay Maximum delay in seconds
     * @param float $minFactor Minimum multiplier for the base delay
     * @param float $maxFactor Maximum multiplier for the base delay
     */
    public function __construct(
        protected ?int $maxDelay = null,
        protected float $minFactor = 1.0,
        protected float $maxFactor = 3.0
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
        $minDelay = $baseDelay * $this->minFactor;
        $maxDelay = min(
            $this->maxDelay ?? PHP_INT_MAX,
            $baseDelay * $this->maxFactor * (1 << $attempt)
        );

        // Generate a random delay between min and max
        $delay = mt_rand(
                (int) ($minDelay * 1000),
                (int) ($maxDelay * 1000)
            ) / 1000;

        return (int) ceil($delay);
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