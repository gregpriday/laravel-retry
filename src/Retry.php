<?php

namespace GregPriday\LaravelRetry;

use Closure;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use RuntimeException;
use Throwable;

class Retry
{
    /**
     * Default maximum number of retries.
     */
    private const DEFAULT_MAX_RETRIES = 3;

    /**
     * Default delay between retries in seconds.
     */
    private const DEFAULT_RETRY_DELAY = 5;

    /**
     * Default timeout for operations in seconds.
     */
    private const DEFAULT_TIMEOUT = 30;

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
     * Execute an operation with retries.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute
     * @param  array<string>  $additionalPatterns  Additional retryable error patterns
     * @param  array<class-string<Throwable>>  $additionalExceptions  Additional retryable exception types
     * @return T The operation result
     *
     * @throws Throwable
     */
    public function run(
        Closure $operation,
        array $additionalPatterns = [],
        array $additionalExceptions = []
    ): mixed {
        $attempt = 0;
        $lastException = null;
        $patterns = [...$this->exceptionManager->getAllPatterns(), ...$additionalPatterns];
        $exceptions = [...$this->exceptionManager->getAllExceptions(), ...$additionalExceptions];

        while ($this->strategy->shouldRetry($attempt, $this->maxRetries, $lastException)) {
            try {
                if (function_exists('set_time_limit')) {
                    set_time_limit($this->timeout);
                }

                return $operation();
            } catch (Throwable $e) {
                $lastException = $e;

                if ($this->isRetryable($e, $patterns, $exceptions)) {
                    $this->handleRetryableError($e, $attempt);
                    $attempt++;
                } else {
                    throw $e;
                }
            }
        }

        throw $lastException ?? new RuntimeException(
            "Operation failed after {$this->maxRetries} attempts"
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

        sleep($delay);
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
}
