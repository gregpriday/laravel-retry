<?php

namespace GregPriday\LaravelRetry\Events;

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
        public int $delay,
        public ?Throwable $exception,
        public int $timestamp
    ) {}
}
