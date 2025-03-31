<?php

namespace GregPriday\LaravelRetry;

use Throwable;

class RetryResult
{
    /**
     * Create a new retry result instance.
     */
    public function __construct(
        private readonly mixed $result = null,
        private readonly ?Throwable $error = null,
        private readonly array $exceptionHistory = []
    ) {}

    /**
     * Handle successful completion of the retry operation.
     *
     * @template T
     *
     * @param  callable(mixed): T  $callback
     */
    public function then(callable $callback): self
    {
        if (! $this->error) {
            try {
                return new self(
                    result: $callback($this->result),
                    error: null,
                    exceptionHistory: $this->exceptionHistory
                );
            } catch (Throwable $e) {
                return new self(
                    result: null,
                    error: $e,
                    exceptionHistory: $this->exceptionHistory
                );
            }
        }

        return $this;
    }

    /**
     * Handle failed completion of the retry operation.
     *
     * @template T
     *
     * @param  callable(Throwable): T  $callback
     */
    public function catch(callable $callback): self
    {
        if ($this->error) {
            try {
                return new self(
                    result: $callback($this->error),
                    error: null,
                    exceptionHistory: $this->exceptionHistory
                );
            } catch (Throwable $e) {
                return new self(
                    result: null,
                    error: $e,
                    exceptionHistory: $this->exceptionHistory
                );
            }
        }

        return $this;
    }

    /**
     * Execute a callback regardless of whether the operation succeeded or failed.
     *
     * @param  callable(): void  $callback
     */
    public function finally(callable $callback): self
    {
        try {
            $callback();
        } catch (Throwable $e) {
            return new self(
                result: null,
                error: $e,
                exceptionHistory: $this->exceptionHistory
            );
        }

        return $this;
    }

    /**
     * Get the result value or throw the error if one occurred.
     *
     * @throws Throwable
     */
    public function value(): mixed
    {
        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }

    /**
     * Get the operation result.
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * Get the error if one occurred.
     */
    public function getError(): ?Throwable
    {
        return $this->error;
    }

    /**
     * Throw the error if one occurred.
     *
     * Note: This method throws the final error that caused the retry operation to fail,
     * not necessarily the first error encountered.
     *
     * @throws Throwable
     */
    public function throw(): void
    {
        if ($this->error) {
            throw $this->error;
        }
    }

    /**
     * Throw the first error that occurred, if any.
     *
     * @throws Throwable
     */
    public function throwFirst(): void
    {
        if (! empty($this->exceptionHistory)) {
            throw $this->exceptionHistory[0]['exception'];
        }

        if ($this->error) {
            throw $this->error;
        }
    }

    /**
     * Get the exception history.
     *
     * @return array<array{
     *   attempt: int,
     *   exception: Throwable,
     *   timestamp: int,
     *   was_retryable: bool
     * }>
     */
    public function getExceptionHistory(): array
    {
        return $this->exceptionHistory;
    }

    /**
     * Check if the operation succeeded.
     */
    public function succeeded(): bool
    {
        return ! $this->error;
    }

    /**
     * Check if the operation failed.
     */
    public function failed(): bool
    {
        return (bool) $this->error;
    }

    /**
     * Send failed retry result to a Dead Letter Queue.
     *
     * @param  string  $operation  Operation name for context
     * @param  array  $context  Additional context data
     * @return string|int|null Dead letter ID or null if not failed or not stored
     */
    public function toDeadLetterQueue(string $operation = '', array $context = []): string|int|null
    {
        if (! $this->failed()) {
            return null;
        }

        // Resolve the DLQ handler from the container if available, otherwise create a new one
        if (class_exists('\Illuminate\Container\Container') && \Illuminate\Container\Container::getInstance()->bound('retry.dead-letter-queue')) {
            $handler = \Illuminate\Container\Container::getInstance()->make('retry.dead-letter-queue');
        } else {
            // Only autoload the handler class if it exists
            if (! class_exists('\GregPriday\LaravelRetry\DeadLetterQueue\DeadLetterQueueHandler')) {
                return null;
            }

            $handler = new \GregPriday\LaravelRetry\DeadLetterQueue\DeadLetterQueueHandler;
        }

        return $handler->handle($this, $operation, $context);
    }
}
