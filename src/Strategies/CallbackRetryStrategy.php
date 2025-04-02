<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

class CallbackRetryStrategy implements RetryStrategy
{
    /**
     * The callback that determines the delay between retry attempts.
     *
     * @var callable
     */
    private $delayCallback;

    /**
     * The callback that determines whether to retry (optional).
     *
     * @var callable|null
     */
    private $shouldRetryCallback;

    /**
     * Base delay value in seconds, used as a reference for the callback.
     *
     * @var float
     */
    private $baseDelay;

    /**
     * Custom options array passed to callbacks for additional context.
     *
     * @var array
     */
    private $options;

    /**
     * The last exception encountered, stored for use in delay calculations.
     *
     * @var Throwable|null
     */
    private $lastException;

    /**
     * Constructor for CallbackRetryStrategy.
     *
     * @param  callable  $delayCallback  Callback to calculate delay (receives $attempt, $baseDelay, $maxAttempts, $exception, $options)
     * @param  callable|null  $shouldRetryCallback  Callback to decide retry (receives $attempt, $maxAttempts, $exception, $options), defaults to attempt-based check
     * @param  float  $baseDelay  Base delay in seconds, defaults to 1.0
     * @param  array  $options  Custom options for callbacks, defaults to empty array
     */
    public function __construct(
        callable $delayCallback,
        ?callable $shouldRetryCallback = null,
        float $baseDelay = 1.0,
        array $options = []
    ) {
        $this->delayCallback = $delayCallback;
        $this->shouldRetryCallback = $shouldRetryCallback ?? fn ($attempt, $maxAttempts) => $attempt < $maxAttempts;
        $this->baseDelay = $baseDelay >= 0 ? $baseDelay : 1.0; // Ensure non-negative
        $this->options = $options;
        $this->lastException = null;
    }

    /**
     * Calculate the delay before the next retry attempt.
     *
     * This implementation handles both calling conventions:
     * - getDelay(int $attempt): float (as per interface)
     * - getDelay(int $attempt, float $baseDelay): float (as called in tests)
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  float|null  $baseDelay  Base delay in seconds (ignored, using constructor value)
     * @return float Delay in seconds
     */
    public function getDelay(int $attempt, ?float $baseDelay = null): float
    {
        // Always use our internal baseDelay, ignore the parameter even if provided
        $delay = (float) call_user_func(
            $this->delayCallback,
            $attempt,
            $this->baseDelay,
            $this->options['max_attempts'] ?? PHP_INT_MAX, // Provide a default max if not set
            $this->lastException,
            $this->options
        );

        // Ensure delay is non-negative
        return max(0, $delay);
    }

    /**
     * Determine whether to retry the operation.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  int  $maxAttempts  Maximum number of retry attempts
     * @param  Throwable|null  $exception  Exception from the last attempt, if any
     * @return bool Whether to retry
     */
    public function shouldRetry(int $attempt, int $maxAttempts, ?Throwable $exception = null): bool
    {
        $this->lastException = $exception; // Store for getDelay use
        $this->options['max_attempts'] = $maxAttempts; // Store for getDelay consistency

        return (bool) call_user_func(
            $this->shouldRetryCallback,
            $attempt,
            $maxAttempts,
            $exception,
            $this->options
        );
    }
}
