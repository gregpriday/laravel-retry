<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

/**
 * FibonacciBackoffStrategy increases delay according to the Fibonacci sequence.
 *
 * This strategy provides a middle ground between exponential and linear growth,
 * offering quick initial retries with a more gradual increase over time (e.g., 1s, 1s, 2s, 3s, 5s, 8s).
 * It supports optional jitter to prevent retry synchronization.
 */
class FibonacciBackoffStrategy implements RetryStrategy
{
    /**
     * Create a new Fibonacci backoff strategy.
     *
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @param  float|null  $maxDelay  Maximum delay in seconds
     * @param  bool  $withJitter  Whether to add random jitter to delays
     * @param  float  $jitterPercent  The percentage of jitter to apply (0.2 means ±20%)
     */
    public function __construct(
        protected float $baseDelay = 1.0,
        protected ?float $maxDelay = null,
        protected bool $withJitter = false,
        protected float $jitterPercent = 0.2
    ) {}

    /**
     * Calculate the delay for the next retry attempt using Fibonacci sequence.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt): float
    {
        // Calculate Fibonacci sequence: 1, 1, 2, 3, 5, 8, 13, 21, 34, ...
        // For attempt 0, we use 1 * baseDelay
        // For attempt 1, we use 1 * baseDelay
        // For attempt 2, we use 2 * baseDelay, and so on

        $fibNumber = $this->fibonacci($attempt + 1);
        $delay = $this->baseDelay * $fibNumber;

        if ($this->withJitter) {
            $delay = $this->addJitter($delay);
        }

        if ($this->maxDelay !== null) {
            $delay = min($delay, $this->maxDelay);
        }

        return max(0.0, $delay);
    }

    /**
     * Calculate the nth Fibonacci number.
     *
     * @param  int  $n  Position in the Fibonacci sequence (1-based)
     * @return int The nth Fibonacci number
     */
    protected function fibonacci(int $n): int
    {
        if ($n <= 0) {
            return 0;
        }

        if ($n <= 2) {
            return 1;
        }

        // Safety check for very large attempt numbers
        // Fibonacci grows exponentially, so we cap at a reasonable limit
        if ($n > 70) {
            // Return a large but safe number (avoid PHP_INT_MAX to prevent overflow when multiplied)
            return 1000000000; // 1 billion
        }

        $a = 1; // First Fibonacci number
        $b = 1; // Second Fibonacci number

        // Calculate Fibonacci number iteratively to avoid recursion overhead
        for ($i = 3; $i <= $n; $i++) {
            $c = $a + $b;
            $a = $b;
            $b = $c;
        }

        return $b;
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
