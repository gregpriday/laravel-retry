<?php

namespace GregPriday\LaravelRetry;

use Closure;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Events\OperationFailedEvent;
use GregPriday\LaravelRetry\Events\OperationSucceededEvent;
use GregPriday\LaravelRetry\Events\RetryingOperationEvent;
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

class Retry
{
    /**
     * Default maximum number of retries.
     */
    private const int DEFAULT_MAX_RETRIES = 3;

    /**
     * Default delay between retries in seconds.
     */
    private const int DEFAULT_RETRY_DELAY = 5;

    /**
     * Default timeout for operations in seconds.
     */
    private const int DEFAULT_TIMEOUT = 30;

    /**
     * List of retryable error patterns.
     *
     * @var array<string>
     */
    protected array $retryablePatterns = [
        '/rate_limit/i',
        '/timeout/i',
        '/server_error/i',
        '/connection refused/i',
        '/connection timed out/i',
        '/temporarily unavailable/i',
    ];

    /**
     * List of retryable exception types.
     *
     * @var array<class-string<Throwable>>
     */
    protected array $retryableExceptions = [];

    /**
     * Collection of exceptions that occurred during retries.
     *
     * @var array<array{
     *    attempt: int,
     *    exception: Throwable,
     *    timestamp: int,
     *    was_retryable: bool
     * }>
     */
    protected array $exceptionHistory = [];

    /**
     * Custom condition for retry logic.
     *
     * @var (Closure(Throwable, array{
     *   attempt: int,
     *   max_retries: int,
     *   remaining_attempts: int,
     *   exception_history: array
     * }): bool)|null
     */
    protected ?Closure $retryCondition = null;

    /**
     * Custom event callbacks for retry lifecycle events.
     *
     * @var array<string, Closure>
     */
    protected array $eventCallbacks = [];

    /**
     * Time when the operation started.
     */
    protected ?float $startTime = null;

    /**
     * Create a new retry instance.
     */
    public function __construct(
        protected ?int $maxRetries = null,
        protected ?int $retryDelay = null,
        protected ?int $timeout = null,
        protected ?Closure $progressCallback = null,
        protected ?RetryStrategy $strategy = null,
        protected ?ExceptionHandlerManager $exceptionManager = null
    ) {
        $this->maxRetries = $maxRetries ?? config('retry.max_retries', self::DEFAULT_MAX_RETRIES);
        $this->retryDelay = $retryDelay ?? config('retry.delay', self::DEFAULT_RETRY_DELAY);
        $this->timeout = $timeout ?? config('retry.timeout', self::DEFAULT_TIMEOUT);
        $this->exceptionManager = $exceptionManager ?? new ExceptionHandlerManager;
        $this->strategy = $strategy ?? new ExponentialBackoffStrategy;
        $this->exceptionManager->registerDefaultHandlers();

        // Initialize the event callbacks array
        $this->eventCallbacks = [
            'onRetry'   => null,
            'onSuccess' => null,
            'onFailure' => null,
        ];
    }

    /**
     * Create a new retry instance.
     */
    public static function make(
        ?int $maxRetries = null,
        ?int $retryDelay = null,
        ?int $timeout = null,
        ?RetryStrategy $strategy = null,
        ?ExceptionHandlerManager $exceptionManager = null,
    ): self {
        return new static(
            maxRetries: $maxRetries,
            retryDelay: $retryDelay,
            timeout: $timeout,
            strategy: $strategy,
            exceptionManager: $exceptionManager,
        );
    }

    /**
     * Set the retry strategy.
     */
    public function withStrategy(RetryStrategy $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Set a custom condition for determining if an operation should be retried.
     *
     * @param Closure(Throwable, array{
     *   attempt: int,
     *   max_retries: int,
     *   remaining_attempts: int,
     *   exception_history: array
     * }): bool $condition
     */
    public function retryIf(Closure $condition): self
    {
        $this->retryCondition = $condition;

        return $this;
    }

    /**
     * Set a custom condition for determining if an operation should not be retried.
     *
     * @param Closure(Throwable, array{
     *   attempt: int,
     *   max_retries: int,
     *   remaining_attempts: int,
     *   exception_history: array
     * }): bool $condition
     */
    public function retryUnless(Closure $condition): self
    {
        $this->retryCondition = fn (Throwable $e, array $context) => ! $condition($e, $context);

        return $this;
    }

    /**
     * Execute an operation with retries.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute
     * @param  array<string>  $additionalPatterns  Additional retryable error patterns
     * @param  array<class-string<Throwable>>  $additionalExceptions  Additional retryable exception types
     * @return RetryResult The operation result wrapped in a RetryResult
     */
    public function run(
        Closure $operation,
        array $additionalPatterns = [],
        array $additionalExceptions = []
    ): RetryResult {
        $this->exceptionHistory = []; // Reset exception history at the start of each run
        $attempt = 0;
        $lastException = null;
        $patterns = [...$this->exceptionManager->getAllPatterns(), ...$additionalPatterns];
        $exceptions = [...$this->exceptionManager->getAllExceptions(), ...$additionalExceptions];
        $this->startTime = microtime(true);

        while ($this->strategy->shouldRetry($attempt, $this->maxRetries, $lastException)) {
            try {
                if (function_exists('set_time_limit')) {
                    set_time_limit($this->timeout);
                }

                $result = $operation();
                $totalTime = microtime(true) - $this->startTime;

                // Dispatch success event
                $this->dispatchOperationSucceededEvent($attempt, $result, $totalTime);

                return new RetryResult(
                    result: $result,
                    error: null,
                    exceptionHistory: $this->exceptionHistory
                );
            } catch (Throwable $e) {
                $lastException = $e;
                $isRetryable = $this->isRetryable($e, $patterns, $exceptions);

                // Record the exception in the history
                $this->exceptionHistory[] = [
                    'attempt'       => $attempt,
                    'exception'     => $e,
                    'timestamp'     => time(),
                    'was_retryable' => $isRetryable,
                ];

                if ($isRetryable) {
                    // Explicitly dispatch retry event before handling the error
                    $this->dispatchRetryingOperationEvent($attempt + 1, $this->strategy->getDelay($attempt, $this->retryDelay), $e);

                    // Then handle the retryable error (which includes sleeping if needed)
                    $this->handleRetryableError($e, $attempt);
                    $attempt++;
                } else {
                    // Dispatch failure event for non-retryable exception
                    $this->dispatchOperationFailedEvent($attempt, $e);

                    return new RetryResult(
                        result: null,
                        error: $e,
                        exceptionHistory: $this->exceptionHistory
                    );
                }
            }
        }

        // Dispatch failure event after all retries exhausted
        $this->dispatchOperationFailedEvent(
            $attempt,
            $lastException ?? new RuntimeException("Operation failed after {$this->maxRetries} attempts")
        );

        return new RetryResult(
            result: null,
            error: $lastException ?? new RuntimeException("Operation failed after {$this->maxRetries} attempts"),
            exceptionHistory: $this->exceptionHistory
        );
    }

    /**
     * Determine if an error is retryable.
     */
    protected function isRetryable(
        Throwable $e,
        array $patterns,
        array $exceptions
    ): bool {
        // First check the custom condition if it exists
        if ($this->retryCondition !== null) {
            $context = [
                'attempt'            => count($this->exceptionHistory),
                'max_retries'        => $this->maxRetries,
                'remaining_attempts' => $this->maxRetries - count($this->exceptionHistory),
                'exception_history'  => $this->exceptionHistory,
            ];

            if (! ($this->retryCondition)($e, $context)) {
                return false;
            }
        }

        // Then check the standard retry conditions
        foreach ($exceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }

        $message = $e->getMessage();
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle a retryable error.
     */
    protected function handleRetryableError(Throwable $e, int $attempt): void
    {
        $remainingAttempts = $this->maxRetries - $attempt;

        if ($remainingAttempts <= 0) {
            return;
        }

        $delay = $this->strategy->getDelay($attempt, $this->retryDelay);
        $message = sprintf(
            'Attempt %d failed: %s. Retrying in %d seconds... (%d attempts remaining)',
            $attempt + 1,
            $e->getMessage(),
            $delay,
            $remainingAttempts
        );

        if (function_exists('logger')) {
            logger()->warning($message);
        }

        if ($this->progressCallback) {
            ($this->progressCallback)($message);
        }

        // Only sleep if delay is positive (important for tests that set delay to 0)
        if ($delay > 0) {
            sleep($delay);
        }
    }

    /**
     * Get the exception history for the last run.
     *
     * @return array<array{
     *    attempt: int,
     *    exception: Throwable,
     *    timestamp: int,
     *    was_retryable: bool
     * }>
     */
    public function getExceptionHistory(): array
    {
        return $this->exceptionHistory;
    }

    /**
     * Get the count of exceptions that occurred during the last run.
     */
    public function getExceptionCount(): int
    {
        return count($this->exceptionHistory);
    }

    /**
     * Get the count of retryable exceptions that occurred during the last run.
     */
    public function getRetryableExceptionCount(): int
    {
        return count(array_filter($this->exceptionHistory, fn ($entry) => $entry['was_retryable']));
    }

    /**
     * Set a progress callback.
     */
    public function withProgress(Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    /**
     * Set the maximum number of retries.
     */
    public function maxRetries(int $retries): self
    {
        $this->maxRetries = $retries;

        return $this;
    }

    /**
     * Set the retry delay.
     */
    public function retryDelay(int $seconds): self
    {
        $this->retryDelay = $seconds;

        return $this;
    }

    /**
     * Set the timeout.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Get the current retry strategy.
     */
    public function getStrategy(): RetryStrategy
    {
        return $this->strategy;
    }

    /**
     * Get the current maximum retries.
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Get the current retry delay.
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Get the current timeout.
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Get the current exception handler manager.
     */
    public function getExceptionManager(): ExceptionHandlerManager
    {
        return $this->exceptionManager;
    }

    /**
     * Set custom event callbacks for retry lifecycle events.
     *
     * @param  array<string, Closure>  $callbacks  Array of callbacks for 'onRetry', 'onSuccess', and 'onFailure'
     */
    public function withEventCallbacks(array $callbacks): self
    {
        // Merge with existing callbacks rather than replacing
        foreach ($callbacks as $key => $callback) {
            if (in_array($key, ['onRetry', 'onSuccess', 'onFailure'])) {
                $this->eventCallbacks[$key] = $callback;
            }
        }

        return $this;
    }

    /**
     * Dispatch the RetryingOperationEvent.
     */
    protected function dispatchRetryingOperationEvent(int $attempt, int $delay, Throwable $exception): void
    {
        if (config('retry.dispatch_events', true)) {
            $event = new RetryingOperationEvent(
                attempt: $attempt,
                maxRetries: $this->maxRetries,
                delay: $delay,
                exception: $exception,
                timestamp: time()
            );

            // Call the onRetry callback if it exists
            if (isset($this->eventCallbacks['onRetry']) && is_callable($this->eventCallbacks['onRetry'])) {
                call_user_func($this->eventCallbacks['onRetry'], $event);
            }

            // Use Laravel Event facade to ensure events are caught by Event::fake in tests
            Event::dispatch($event);
        }
    }

    /**
     * Dispatch the OperationSucceededEvent.
     */
    protected function dispatchOperationSucceededEvent(int $attempt, mixed $result, float $totalTime): void
    {
        if (config('retry.dispatch_events', true)) {
            $event = new OperationSucceededEvent(
                attempt: $attempt,
                result: $result,
                totalTime: $totalTime,
                timestamp: time()
            );

            // Call the onSuccess callback if it exists
            if (isset($this->eventCallbacks['onSuccess']) && is_callable($this->eventCallbacks['onSuccess'])) {
                call_user_func($this->eventCallbacks['onSuccess'], $event);
            }

            // Use Laravel Event facade to ensure events are caught by Event::fake in tests
            Event::dispatch($event);
        }
    }

    /**
     * Dispatch the OperationFailedEvent.
     */
    protected function dispatchOperationFailedEvent(int $attempt, ?Throwable $error): void
    {
        if (config('retry.dispatch_events', true)) {
            $event = new OperationFailedEvent(
                attempt: $attempt,
                error: $error,
                exceptionHistory: $this->exceptionHistory,
                timestamp: time()
            );

            // Call the onFailure callback if it exists
            if (isset($this->eventCallbacks['onFailure']) && is_callable($this->eventCallbacks['onFailure'])) {
                call_user_func($this->eventCallbacks['onFailure'], $event);
            }

            // Use Laravel Event facade to ensure events are caught by Event::fake in tests
            Event::dispatch($event);
        }
    }
}
