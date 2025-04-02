<?php

namespace GregPriday\LaravelRetry\Events;

use GregPriday\LaravelRetry\RetryContext;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class RetryingOperationEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $attempt,
        public int $maxRetries,
        public float $delay,
        public ?Throwable $exception,
        public int $timestamp,
        public RetryContext $context,
        public array $metrics = [],
        public array $metadata = []
    ) {
        $this->metrics = $context->getMetrics();
        $this->metadata = $context->getMetadata();
    }

    /**
     * Get a summary of the retry operation.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'operation_id' => $this->context->getOperationId(),
            'attempt'      => $this->attempt,
            'max_retries'  => $this->maxRetries,
            'delay'        => $this->delay,
            'exception'    => $this->exception ? [
                'class'   => get_class($this->exception),
                'message' => $this->exception->getMessage(),
                'code'    => $this->exception->getCode(),
            ] : null,
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
     * Get the total elapsed time so far.
     */
    public function getElapsedTime(): float
    {
        return microtime(true) - $this->context->getStartTime();
    }

    /**
     * Get the total delay time so far.
     */
    public function getTotalDelay(): float
    {
        return $this->context->getTotalDelay();
    }

    /**
     * Get the retry context.
     */
    public function getContext(): RetryContext
    {
        return $this->context;
    }
}
