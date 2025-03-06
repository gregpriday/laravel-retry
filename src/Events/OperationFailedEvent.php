<?php

namespace GregPriday\LaravelRetry\Events;

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
        public int $timestamp
    ) {}
}
