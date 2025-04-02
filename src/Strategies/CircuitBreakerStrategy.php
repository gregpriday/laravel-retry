<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class CircuitBreakerStrategy implements RetryStrategy
{
    private const string CIRCUIT_OPEN = 'open';

    private const string CIRCUIT_CLOSED = 'closed';

    private const string CIRCUIT_HALF_OPEN = 'half-open';

    private string $state = self::CIRCUIT_CLOSED;

    private int $failureCount = 0;

    private ?int $openedAt = null;

    /**
     * Create a new circuit breaker strategy.
     *
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @param  RetryStrategy  $innerStrategy  The wrapped retry strategy
     * @param  int  $failureThreshold  Number of failures before opening circuit
     * @param  float  $resetTimeout  Seconds before attempting reset (half-open)
     */
    public function __construct(
        protected float $baseDelay,
        protected RetryStrategy $innerStrategy,
        protected int $failureThreshold = 5,
        protected float $resetTimeout = 60.0
    ) {}

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt): float
    {
        // Delay calculation doesn't depend on circuit state directly,
        // but relies on the inner strategy.
        return $this->innerStrategy->getDelay($attempt);
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
        // 1. Process the outcome of the *previous* attempt to update the state.
        // This handles transitions like CLOSED -> OPEN, HALF_OPEN -> CLOSED, HALF_OPEN -> OPEN.
        $this->updateStateBasedOnLastOutcome($lastException);

        // 2. Check if we should transition from OPEN to HALF_OPEN *now* based on time.
        if ($this->state === self::CIRCUIT_OPEN && $this->shouldAttemptReset()) {
            // Timeout has passed, transition to half-open *before* making the decision
            // for the current attempt.
            $this->state = self::CIRCUIT_HALF_OPEN;
            $this->resetFailureCount(); // Reset for the single test attempt
            // Note: openedAt remains set until the circuit closes successfully,
            // indicating when the last OPEN period started.
        }

        // 3. Make the retry decision based on the *current* (potentially updated) state.
        if ($this->state === self::CIRCUIT_OPEN) {
            // Still open (timeout hasn't passed or transition didn't happen)
            // Deny retry immediately.
            return false;
        }

        // If state is CLOSED or HALF_OPEN, defer to the inner strategy
        // (which respects maxAttempts). The HALF_OPEN state allows exactly one
        // attempt through the inner strategy check. The outcome of this attempt
        // will be processed by updateStateBasedOnLastOutcome in the *next* call.
        return $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException);
    }

    /**
     * Updates the circuit state based on the outcome of the *last* completed attempt.
     * This determines the state *before* the next attempt runs.
     */
    private function updateStateBasedOnLastOutcome(?Throwable $lastException): void
    {
        if ($this->state === self::CIRCUIT_HALF_OPEN) {
            if ($lastException !== null) {
                // Failure during HALF_OPEN attempt -> Re-open the circuit
                $this->openCircuit();
            } else {
                // Success during HALF_OPEN attempt -> Close the circuit
                $this->closeCircuit();
            }
        } elseif ($this->state === self::CIRCUIT_CLOSED) {
            if ($lastException !== null) {
                // Failure during CLOSED state
                $this->failureCount++; // Increment first to reflect this failure

                // Check if the failure count *now exceeds* the threshold
                if ($this->failureCount > $this->failureThreshold) {
                    $this->openCircuit(); // Open circuit
                }
                // If not met, remain CLOSED, failure count is already updated.
            } else {
                // Success during CLOSED state
                $this->resetFailureCount(); // Reset count on success
            }
        }
        // No state change logic needed here if state is already OPEN.
        // The transition *out* of OPEN happens in shouldRetry based on time.
    }

    private function openCircuit(): void
    {
        $this->state = self::CIRCUIT_OPEN;
        $this->openedAt = now()->getTimestamp();
        // Failure count becomes irrelevant in OPEN state, will be reset on transition
    }

    private function closeCircuit(): void
    {
        $this->state = self::CIRCUIT_CLOSED;
        $this->openedAt = null;
        $this->resetFailureCount();
    }

    private function resetFailureCount(): void
    {
        $this->failureCount = 0;
    }

    private function shouldAttemptReset(): bool
    {
        // Use now()->getTimestamp() instead of time()
        // Ensure openedAt is not null before comparison
        return $this->openedAt !== null && (now()->getTimestamp() - $this->openedAt) >= $this->resetTimeout;
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

    /**
     * Get the reset timeout in seconds.
     */
    public function getResetTimeout(): float
    {
        return $this->resetTimeout;
    }
}
