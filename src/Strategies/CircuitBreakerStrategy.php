<?php

namespace GregPriday\LaravelRetry\Strategies;

use Exception;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CircuitBreakerStrategy implements the Circuit Breaker pattern for retry operations.
 *
 * After a defined number of consecutive failures, the circuit "opens" to prevent
 * additional retry attempts for a specified period. Once the timeout elapses, a single
 * "test" attempt is allowed in the half-open state. Success closes the circuit, allowing
 * normal retry behavior, while failure reopens it. This protects downstream services from
 * cascading failures during outages.
 */
class CircuitBreakerStrategy implements RetryStrategy
{
    private const CIRCUIT_OPEN = 'open';

    private const CIRCUIT_CLOSED = 'closed';

    private const CIRCUIT_HALF_OPEN = 'half-open';

    /**
     * Create a new circuit breaker strategy.
     *
     * @param  RetryStrategy  $innerStrategy  The wrapped retry strategy
     * @param  int  $failureThreshold  Number of failures before opening circuit
     * @param  float  $resetTimeout  Seconds before attempting reset (half-open)
     * @param  string|null  $cacheKey  Unique identifier for this circuit breaker instance (should be service-specific)
     * @param  int  $cacheTtl  Cache TTL in minutes (default 1 day)
     */
    public function __construct(
        protected RetryStrategy $innerStrategy,
        protected int $failureThreshold = 5,
        protected float $resetTimeout = 60.0,
        protected ?string $cacheKey = null,
        protected int $cacheTtl = 1440
    ) {
        // If no cacheKey provided, generate a backtrace-based unique identifier
        if ($this->cacheKey === null) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $backtrace[1] ?? $backtrace[0] ?? null;
            $file = $caller['file'] ?? 'unknown';
            $line = $caller['line'] ?? 'unknown';
            $this->cacheKey = md5($file.':'.$line);
        }

        // Initialize circuit state in cache if it doesn't exist
        if (! $this->getCachedState()) {
            $this->closeCircuit();
        }
    }

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
        try {
            // 1. Process the outcome of the *previous* attempt to update the state.
            // This handles transitions like CLOSED -> OPEN, HALF_OPEN -> CLOSED, HALF_OPEN -> OPEN.
            $this->updateStateBasedOnLastOutcome($lastException);

            // 2. Check if we should transition from OPEN to HALF_OPEN *now* based on time.
            $state = $this->getCachedState();
            $openedAt = $this->getCachedOpenedAt();

            if ($state === self::CIRCUIT_OPEN && $this->shouldAttemptReset($openedAt)) {
                // Timeout has passed, transition to half-open *before* making the decision
                // for the current attempt.
                $this->setCachedState(self::CIRCUIT_HALF_OPEN);
                $this->resetFailureCount(); // Reset for the single test attempt
                // Note: openedAt remains set until the circuit closes successfully,
                // indicating when the last OPEN period started.
            }

            // 3. Make the retry decision based on the *current* (potentially updated) state.
            $state = $this->getCachedState(); // Refresh state
            if ($state === self::CIRCUIT_OPEN) {
                // Still open (timeout hasn't passed or transition didn't happen)
                // Deny retry immediately.
                return false;
            }

            // If state is CLOSED or HALF_OPEN, defer to the inner strategy
            // (which respects maxAttempts). The HALF_OPEN state allows exactly one
            // attempt through the inner strategy check. The outcome of this attempt
            // will be processed by updateStateBasedOnLastOutcome in the *next* call.
            return $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException);
        } catch (Exception $e) {
            // If cache operations fail, log the issue and default to allowing retries
            // This assumes it's better to potentially overwhelm a system than to
            // incorrectly prevent retries due to cache failures
            Log::warning('Circuit breaker cache operation failed: '.$e->getMessage(), [
                'cacheKey'  => $this->cacheKey,
                'exception' => $e,
            ]);

            return $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException);
        }
    }

    /**
     * Updates the circuit state based on the outcome of the *last* completed attempt.
     * This determines the state *before* the next attempt runs.
     */
    private function updateStateBasedOnLastOutcome(?Throwable $lastException): void
    {
        $state = $this->getCachedState();

        if ($state === self::CIRCUIT_HALF_OPEN) {
            if ($lastException !== null) {
                // Failure during HALF_OPEN attempt -> Re-open the circuit
                $this->openCircuit();
            } else {
                // Success during HALF_OPEN attempt -> Close the circuit
                $this->closeCircuit();
            }
        } elseif ($state === self::CIRCUIT_CLOSED) {
            if ($lastException !== null) {
                // Failure during CLOSED state
                // Use atomic increment operation if the driver supports it
                $this->incrementFailureCount();

                // Check if the failure count now exceeds the threshold
                if ($this->getCachedFailureCount() > $this->failureThreshold) {
                    $this->openCircuit(); // Open circuit
                }
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
        $this->setCachedState(self::CIRCUIT_OPEN);
        $this->setCachedOpenedAt(now()->getTimestamp());
        // Failure count becomes irrelevant in OPEN state, will be reset on transition
    }

    private function closeCircuit(): void
    {
        $this->setCachedState(self::CIRCUIT_CLOSED);
        $this->setCachedOpenedAt(null);
        $this->resetFailureCount();
    }

    private function resetFailureCount(): void
    {
        $this->setCachedFailureCount(0);
    }

    /**
     * Atomically increment the failure count
     */
    private function incrementFailureCount(): void
    {
        try {
            // First try atomic increment if the driver supports it
            $result = Cache::increment($this->getCacheKey('failure_count'));

            // If the key doesn't exist yet, increment returns false
            if ($result === false) {
                $this->setCachedFailureCount(1);
            }
        } catch (Exception $e) {
            // Fall back to non-atomic operation if increment fails
            $count = $this->getCachedFailureCount() + 1;
            $this->setCachedFailureCount($count);
        }
    }

    private function shouldAttemptReset(?int $openedAt): bool
    {
        return $openedAt !== null && (now()->getTimestamp() - $openedAt) >= $this->resetTimeout;
    }

    /**
     * Generate a fully qualified cache key for the given property
     */
    private function getCacheKey(string $property): string
    {
        return "circuit_breaker:{$this->cacheKey}:{$property}";
    }

    /**
     * Get circuit state from cache
     */
    private function getCachedState(): ?string
    {
        try {
            return Cache::tags(['circuit_breakers'])->get($this->getCacheKey('state'));
        } catch (Exception $e) {
            // If tagging is not supported by the cache driver, fall back to regular cache
            return Cache::get($this->getCacheKey('state'));
        }
    }

    /**
     * Set circuit state in cache
     */
    private function setCachedState(string $state): void
    {
        try {
            Cache::tags(['circuit_breakers'])->put($this->getCacheKey('state'), $state, $this->cacheTtl);
        } catch (Exception $e) {
            // If tagging is not supported by the cache driver, fall back to regular cache
            Cache::put($this->getCacheKey('state'), $state, $this->cacheTtl);
        }
    }

    /**
     * Get failure count from cache
     */
    private function getCachedFailureCount(): int
    {
        try {
            return Cache::tags(['circuit_breakers'])->get($this->getCacheKey('failure_count'), 0);
        } catch (Exception $e) {
            // If tagging is not supported by the cache driver, fall back to regular cache
            return Cache::get($this->getCacheKey('failure_count'), 0);
        }
    }

    /**
     * Set failure count in cache
     */
    private function setCachedFailureCount(int $count): void
    {
        try {
            Cache::tags(['circuit_breakers'])->put($this->getCacheKey('failure_count'), $count, $this->cacheTtl);
        } catch (Exception $e) {
            // If tagging is not supported by the cache driver, fall back to regular cache
            Cache::put($this->getCacheKey('failure_count'), $count, $this->cacheTtl);
        }
    }

    /**
     * Get opened at timestamp from cache
     */
    private function getCachedOpenedAt(): ?int
    {
        try {
            return Cache::tags(['circuit_breakers'])->get($this->getCacheKey('opened_at'));
        } catch (Exception $e) {
            // If tagging is not supported by the cache driver, fall back to regular cache
            return Cache::get($this->getCacheKey('opened_at'));
        }
    }

    /**
     * Set opened at timestamp in cache
     */
    private function setCachedOpenedAt(?int $timestamp): void
    {
        try {
            if ($timestamp === null) {
                Cache::tags(['circuit_breakers'])->forget($this->getCacheKey('opened_at'));
            } else {
                Cache::tags(['circuit_breakers'])->put($this->getCacheKey('opened_at'), $timestamp, $this->cacheTtl);
            }
        } catch (Exception $e) {
            // If tagging is not supported by the cache driver, fall back to regular cache
            if ($timestamp === null) {
                Cache::forget($this->getCacheKey('opened_at'));
            } else {
                Cache::put($this->getCacheKey('opened_at'), $timestamp, $this->cacheTtl);
            }
        }
    }

    /**
     * Get the current circuit state (useful for testing and monitoring)
     */
    public function getCircuitState(): string
    {
        return $this->getCachedState() ?? self::CIRCUIT_CLOSED;
    }

    /**
     * Get the current failure count (useful for testing and monitoring)
     */
    public function getFailureCount(): int
    {
        return $this->getCachedFailureCount();
    }

    /**
     * Get the reset timeout in seconds.
     */
    public function getResetTimeout(): float
    {
        return $this->resetTimeout;
    }

    /**
     * Reset the circuit breaker state completely.
     * Useful for testing or administrative operations.
     */
    public function reset(): void
    {
        $this->closeCircuit();
    }
}
