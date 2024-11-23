<?php

namespace GregPriday\LaravelRetry\Contracts;

interface RetryableExceptionHandler
{
    /**
     * Get the list of exception patterns to match against.
     *
     * @return array<string>
     */
    public function getPatterns(): array;

    /**
     * Get the list of exception classes that should be retried.
     *
     * @return array<class-string<\Throwable>>
     */
    public function getExceptions(): array;

    /**
     * Determine if this handler is applicable.
     *
     * Checks if the required classes/packages are available.
     */
    public function isApplicable(): bool;
}