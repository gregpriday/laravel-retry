<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class ExponentialBackoffStrategy implements RetryStrategy
{
    /**
     * Create a new exponential backoff strategy.
     *
     * @param float $multiplier The multiplier for each subsequent retry
     * @param int|null $maxDelay Maximum delay in seconds
     * @param bool $withJitter Whether to add random jitter to delays
     */
    public function __construct(
        protected float $multiplier = 2.0,
        protected ?int $maxDelay = null,
        protected bool $withJitter = false
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
        // Calculate exponential delay: baseDelay * multiplier^attempt
        $delay = $baseDelay * pow($this->multiplier, $attempt);

        if ($this->withJitter) {
            $delay = $this->addJitter($delay);
        }

        if ($this->maxDelay !== null) {
            $delay = min($delay, $this->maxDelay);
        }

        return (int) ceil($delay);
    }

    /**
     * Add random jitter to the delay.
     *
     * @param float $delay Base delay value
     * @return float Delay with jitter
     */
    protected function addJitter(float $delay): float
    {
        // Add ±20% random jitter
        return $delay * (mt_rand(80, 120) / 100);
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