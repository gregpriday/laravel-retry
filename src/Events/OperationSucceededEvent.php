<?php

namespace GregPriday\LaravelRetry\Events;

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
        public int $timestamp
    ) {}
}
