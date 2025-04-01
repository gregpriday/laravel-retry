<?php

namespace GregPriday\LaravelRetry\Events;

use GregPriday\LaravelRetry\RetryContext;
use Illuminate\Foundation\Events\Dispatchable;

class OperationSucceededEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $attempt,
        public mixed $result,
        public ?float $totalTime,
        public int $timestamp,
        public RetryContext $context,
        public array $metrics = [],
        public array $metadata = []
    ) {
        $this->metrics = $context->getMetrics();
        $this->metadata = $context->getMetadata();
    }

    /**
     * Get a summary of the successful operation.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'operation_id' => $this->context->getOperationId(),
            'attempt'      => $this->attempt,
            'total_time'   => $this->totalTime,
            'timestamp'    => $this->timestamp,
            'metrics'      => $this->metrics,
            'metadata'     => $this->metadata,
            'result_type'  => is_object($this->result) ? get_class($this->result) : gettype($this->result),
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
}
