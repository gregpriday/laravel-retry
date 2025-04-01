<?php

namespace GregPriday\LaravelRetry\Events;

use GregPriday\LaravelRetry\RetryContext;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class OperationFailedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $attempt,
        public ?Throwable $error,
        public array $exceptionHistory,
        public int $timestamp,
        public RetryContext $context,
        public array $metrics = [],
        public array $metadata = []
    ) {
        $this->metrics = $context->getMetrics();
        $this->metadata = $context->getMetadata();
    }

    /**
     * Get a summary of the failed operation.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'operation_id' => $this->context->getOperationId(),
            'attempt'      => $this->attempt,
            'error'        => $this->error ? [
                'class'   => get_class($this->error),
                'message' => $this->error->getMessage(),
                'code'    => $this->error->getCode(),
            ] : null,
            'exception_history' => array_map(function ($entry) {
                return [
                    'attempt'   => $entry['attempt'],
                    'exception' => [
                        'class'   => get_class($entry['exception']),
                        'message' => $entry['exception']->getMessage(),
                        'code'    => $entry['exception']->getCode(),
                    ],
                    'timestamp'     => $entry['timestamp'],
                    'was_retryable' => $entry['was_retryable'],
                    'delay'         => $entry['delay'],
                    'duration'      => $entry['duration'],
                ];
            }, $this->exceptionHistory),
            'timestamp' => $this->timestamp,
            'metrics'   => $this->metrics,
            'metadata'  => $this->metadata,
        ];
    }

    /**
     * Get the operation ID.
     */
    public function getOperationId(): string
    {
        return $this->context->getOperationId();
    }

    /**
     * Get the total number of attempts made.
     */
    public function getTotalAttempts(): int
    {
        return $this->context->getTotalAttempts();
    }

    /**
     * Get the total delay time.
     */
    public function getTotalDelay(): float
    {
        return $this->context->getTotalDelay();
    }

    /**
     * Get the total elapsed time.
     */
    public function getElapsedTime(): float
    {
        return microtime(true) - $this->context->getStartTime();
    }

    /**
     * Get the number of retryable exceptions.
     */
    public function getRetryableExceptionCount(): int
    {
        return count(array_filter($this->exceptionHistory, fn ($e) => $e['was_retryable']));
    }

    /**
     * Get the last exception that occurred.
     */
    public function getLastException(): ?Throwable
    {
        if (empty($this->exceptionHistory)) {
            return $this->error;
        }

        return end($this->exceptionHistory)['exception'];
    }
}
