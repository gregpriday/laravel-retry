<?php

namespace GregPriday\LaravelRetry\DeadLetterQueue;

use Closure;
use GregPriday\LaravelRetry\RetryResult;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeadLetterQueueHandler
{
    /**
     * The dead letter queue storage.
     */
    protected ?DeadLetterQueueStorage $storage = null;

    /**
     * Custom handler for failed operations.
     */
    protected ?Closure $handler = null;

    /**
     * Whether to automatically log failed operations.
     */
    protected bool $shouldLog = true;

    /**
     * The log level to use when logging failures.
     */
    protected string $logLevel = 'warning';

    /**
     * Create a new dead letter queue handler.
     *
     * @param  DeadLetterQueueStorage|null  $storage  Storage implementation for failed operations
     * @param  bool  $shouldLog  Whether to automatically log failed operations
     * @param  string  $logLevel  Level to log at (emergency, alert, critical, error, warning, notice, info, debug)
     * @param  Closure|null  $handler  Custom handler for failed operations
     */
    public function __construct(
        ?DeadLetterQueueStorage $storage = null,
        bool $shouldLog = true,
        string $logLevel = 'warning',
        ?Closure $handler = null
    ) {
        $this->storage = $storage ?? new DatabaseDeadLetterQueueStorage;
        $this->shouldLog = $shouldLog;
        $this->logLevel = $logLevel;
        $this->handler = $handler;
    }

    /**
     * Create a new dead letter queue handler instance.
     */
    public static function make(
        ?DeadLetterQueueStorage $storage = null,
        bool $shouldLog = true,
        string $logLevel = 'warning'
    ): self {
        return new self($storage, $shouldLog, $logLevel);
    }

    /**
     * Handle a failed retry operation by storing it in the dead letter queue.
     *
     * @param  RetryResult  $result  The retry result
     * @param  string  $operation  Name of the operation or empty string
     * @param  array  $context  Additional context data
     * @return string|int|null ID of the stored dead letter or null if not stored
     */
    public function handle(RetryResult $result, string $operation = '', array $context = []): string|int|null
    {
        if (! $result->failed()) {
            return null;
        }

        $error = $result->getError();
        $exceptionHistory = $result->getExceptionHistory();

        // Prepare the dead letter data
        $deadLetter = [
            'operation'         => $operation,
            'error_message'     => $error?->getMessage(),
            'error_class'       => $error ? get_class($error) : null,
            'error_trace'       => $error?->getTraceAsString(),
            'exception_history' => $exceptionHistory,
            'context'           => $context,
            'created_at'        => now(),
        ];

        // Log the failure if enabled
        if ($this->shouldLog) {
            $this->logFailure($error, $operation, count($exceptionHistory));
        }

        // Run custom handler if provided
        if ($this->handler) {
            ($this->handler)($result, $operation, $context);
        }

        // Store in the dead letter queue
        return $this->storage->store($deadLetter);
    }

    /**
     * Process items in the dead letter queue.
     *
     * @param  Closure  $processor  Function to process each dead letter
     * @param  int  $limit  Maximum number of items to process
     * @param  array  $filters  Optional filters to apply when retrieving items
     * @return array Array of processing results
     */
    public function processQueue(Closure $processor, int $limit = 100, array $filters = []): array
    {
        $deadLetters = $this->storage->retrieve($limit, $filters);
        $results = [];

        foreach ($deadLetters as $id => $deadLetter) {
            try {
                $processingResult = $processor($deadLetter, $id);
                $this->storage->markAsProcessed($id, $processingResult);
                $results[$id] = [
                    'success' => true,
                    'result'  => $processingResult,
                ];
            } catch (Throwable $e) {
                $this->storage->markAsFailed($id, $e->getMessage());
                $results[$id] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];

                if ($this->shouldLog) {
                    Log::error("Failed to process dead letter queue item {$id}: ".$e->getMessage(), [
                        'exception'   => $e,
                        'dead_letter' => $deadLetter,
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * Set a custom handler for failed operations.
     *
     * @param  Closure  $handler  Function(RetryResult $result, string $operation, array $context): void
     */
    public function withHandler(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Enable or disable automatic logging.
     *
     * @param  bool  $shouldLog  Whether to log failures
     */
    public function withLogging(bool $shouldLog = true): self
    {
        $this->shouldLog = $shouldLog;

        return $this;
    }

    /**
     * Change the storage implementation.
     *
     * @param  DeadLetterQueueStorage  $storage  New storage implementation
     */
    public function withStorage(DeadLetterQueueStorage $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    /**
     * Log a failure to the appropriate channel.
     *
     * @param  Throwable|null  $error  The error that occurred
     * @param  string  $operation  Name of the operation
     * @param  int  $attempts  Number of attempts made
     */
    protected function logFailure(?Throwable $error, string $operation, int $attempts): void
    {
        $operationName = $operation ?: 'Unnamed operation';
        $errorMessage = $error ? $error->getMessage() : 'Unknown error';

        $message = "Retry operation failed after {$attempts} attempts: {$operationName}. Error: {$errorMessage}";
        $context = [
            'operation' => $operation,
            'error'     => $error,
            'attempts'  => $attempts,
        ];

        // Log at the configured level
        Log::{$this->logLevel}($message, $context);
    }

    /**
     * Set the log level.
     *
     * @param  string  $level  Log level (emergency, alert, critical, error, warning, notice, info, debug)
     */
    public function withLogLevel(string $level): self
    {
        $this->logLevel = $level;

        return $this;
    }
}
