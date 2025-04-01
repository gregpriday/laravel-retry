<?php

namespace GregPriday\LaravelRetry;

use Throwable;

class RetryContext
{
    /**
     * @var array<array{
     *   attempt: int,
     *   exception: Throwable,
     *   timestamp: int,
     *   was_retryable: bool,
     *   delay: float|null,
     *   duration: float|null
     * }>
     */
    protected array $exceptionHistory = [];

    /**
     * @var array<string, mixed>
     */
    protected array $metadata = [];

    /**
     * @var array<string, float>
     */
    protected array $metrics = [
        'total_duration'           => 0.0,
        'total_delay'              => 0.0,
        'average_attempt_duration' => 0.0,
        'min_attempt_duration'     => PHP_FLOAT_MAX,
        'max_attempt_duration'     => 0.0,
    ];

    /**
     * Counter for attempts with durations.
     */
    protected int $durationAttempts = 0;

    /**
     * Counter for total attempts.
     */
    protected int $totalAttempts = 1;  // Start at 1 for initial attempt

    /**
     * Create a new retry context instance.
     */
    public function __construct(
        protected int $maxRetries,
        protected float $startTime,
        protected ?string $operationId = null
    ) {
        $this->operationId = $operationId ?? uniqid('retry_', true);
    }

    /**
     * Record an attempt in the exception history.
     */
    public function recordAttempt(
        int $attempt,
        ?Throwable $exception,
        bool $wasRetryable,
        ?float $delay = null,
        ?float $duration = null
    ): void {
        $this->totalAttempts++;

        if ($exception) {
            $this->exceptionHistory[] = [
                'attempt'       => $attempt,
                'exception'     => $exception,
                'timestamp'     => time(),
                'was_retryable' => $wasRetryable,
                'delay'         => $delay,
                'duration'      => $duration,
            ];
        }

        if ($duration !== null) {
            $this->durationAttempts++;
            $this->updateMetrics($duration);
        }
    }

    /**
     * Update performance metrics.
     */
    protected function updateMetrics(float $duration): void
    {
        $this->metrics['total_duration'] += $duration;
        $this->metrics['min_attempt_duration'] = min($this->metrics['min_attempt_duration'], $duration);
        $this->metrics['max_attempt_duration'] = max($this->metrics['max_attempt_duration'], $duration);
        $this->metrics['average_attempt_duration'] = $this->metrics['total_duration'] / $this->durationAttempts;
    }

    /**
     * Add metadata to the context.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function addMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata, $metadata);
    }

    /**
     * Get all metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get specific metadata value.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get all metrics.
     *
     * @return array<string, float>
     */
    public function getMetrics(): array
    {
        // Calculate final metrics
        $endTime = microtime(true);
        $this->metrics['total_elapsed_time'] = $endTime - $this->startTime;

        return $this->metrics;
    }

    /**
     * Get the exception history.
     *
     * @return array<array{
     *   attempt: int,
     *   exception: Throwable,
     *   timestamp: int,
     *   was_retryable: bool,
     *   delay: float|null,
     *   duration: float|null
     * }>
     */
    public function getExceptionHistory(): array
    {
        return $this->exceptionHistory;
    }

    /**
     * Get the operation ID.
     */
    public function getOperationId(): string
    {
        return $this->operationId;
    }

    /**
     * Get the maximum number of retries.
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Get the start time.
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * Get the total number of attempts made.
     */
    public function getTotalAttempts(): int
    {
        return $this->totalAttempts;
    }

    /**
     * Get the total delay time.
     */
    public function getTotalDelay(): float
    {
        return array_sum(array_column($this->exceptionHistory, 'delay') ?: [0]);
    }

    /**
     * Get a summary of the retry operation.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'operation_id'         => $this->operationId,
            'total_attempts'       => $this->getTotalAttempts(),
            'max_retries'          => $this->maxRetries,
            'total_exceptions'     => count($this->exceptionHistory),
            'retryable_exceptions' => count(array_filter($this->exceptionHistory, fn ($e) => $e['was_retryable'])),
            'metrics'              => $this->getMetrics(),
            'metadata'             => $this->metadata,
        ];
    }
}
