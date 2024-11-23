<?php

namespace GregPriday\LaravelRetry\Exceptions\Handlers;

use GregPriday\LaravelRetry\Contracts\RetryableExceptionHandler;

abstract class BaseHandler implements RetryableExceptionHandler
{
    /**
     * Default patterns that apply to most handlers.
     *
     * @return array<string>
     */
    protected function getDefaultPatterns(): array
    {
        return [
            '/timeout/i',
            '/temporarily unavailable/i',
            '/server error/i',
            '/connection refused/i',
        ];
    }

    /**
     * Get handler-specific patterns.
     *
     * @return array<string>
     */
    abstract protected function getHandlerPatterns(): array;

    /**
     * Get handler-specific exception classes.
     *
     * @return array<class-string<\Throwable>>
     */
    abstract protected function getHandlerExceptions(): array;

    /**
     * Get all patterns including defaults.
     *
     * @return array<string>
     */
    public function getPatterns(): array
    {
        return array_merge(
            $this->getDefaultPatterns(),
            $this->getHandlerPatterns()
        );
    }

    /**
     * Get all exception classes.
     *
     * @return array<class-string<\Throwable>>
     */
    public function getExceptions(): array
    {
        return $this->getHandlerExceptions();
    }

    /**
     * Determine if this handler is applicable.
     *
     * Checks if the required classes/packages are available.
     */
    abstract public function isApplicable(): bool;
}
