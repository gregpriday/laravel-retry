<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class CircuitBreakerStrategy implements RetryStrategy
{
    private const CIRCUIT_OPEN = 'open';

    private const CIRCUIT_CLOSED = 'closed';

    private const CIRCUIT_HALF_OPEN = 'half-open';

    private string $state = self::CIRCUIT_CLOSED;

    private int $failureCount = 0;

    private ?int $openedAt = null;

    /**
     * Create a new circuit breaker strategy.
     *
     * @param  RetryStrategy  $innerStrategy  The wrapped retry strategy
     * @param  int  $failureThreshold  Number of failures before opening circuit
     * @param  int  $resetTimeout  Seconds before attempting reset (half-open)
     */
    public function __construct(
        protected RetryStrategy $innerStrategy,
        protected int $failureThreshold = 5,
        protected int $resetTimeout = 60
    ) {
        $this->closeCircuit(); // Ensure we start in a clean state
    }

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  float  $baseDelay  Base delay in seconds
     * @return int Delay in seconds
     */
    public function getDelay(int $attempt, float $baseDelay): int
    {
        return $this->innerStrategy->getDelay($attempt, $baseDelay);
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
        // First check if we've exceeded max attempts
        if (! $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException)) {
            return false;
        }

        // Handle OPEN circuit
        if ($this->state === self::CIRCUIT_OPEN) {
            if ($this->shouldAttemptReset()) {
                $this->setCircuitState(self::CIRCUIT_HALF_OPEN);

                return true;
            }

            return false;
        }

        // Handle HALF-OPEN circuit
        if ($this->state === self::CIRCUIT_HALF_OPEN) {
            if ($lastException) {
                $this->openCircuit();

                return false;
            }
            $this->closeCircuit(); // Success in half-open state closes the circuit

            return true;
        }

        // Handle CLOSED circuit
        if ($lastException) {
            $this->incrementFailureCount();
            if ($this->failureCount > $this->failureThreshold) {
                $this->openCircuit();

                return false;
            }
        } else {
            $this->resetFailureCount(); // Reset on success
        }

        return true;
    }

    protected function setCircuitState(string $state): void
    {
        $this->state = $state;
        if ($state === self::CIRCUIT_OPEN) {
            $this->openedAt = time();
        }
    }

    protected function incrementFailureCount(): void
    {
        $this->failureCount++;
    }

    protected function resetFailureCount(): void
    {
        $this->failureCount = 0;
    }

    protected function openCircuit(): void
    {
        $this->setCircuitState(self::CIRCUIT_OPEN);
    }

    protected function closeCircuit(): void
    {
        $this->setCircuitState(self::CIRCUIT_CLOSED);
        $this->resetFailureCount();
    }

    protected function shouldAttemptReset(): bool
    {
        return $this->openedAt && (time() - $this->openedAt) >= $this->resetTimeout;
    }

    /**
     * Get the current circuit state (useful for testing and monitoring)
     */
    public function getCircuitState(): string
    {
        return $this->state;
    }

    /**
     * Get the current failure count (useful for testing and monitoring)
     */
    public function getFailureCount(): int
    {
        return $this->failureCount;
    }
}
