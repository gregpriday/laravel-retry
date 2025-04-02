<?php

namespace GregPriday\LaravelRetry\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed run(\Closure $operation, array $additionalPatterns = [], array $additionalExceptions = [])
 * @method static \GregPriday\LaravelRetry\Retry withProgress(\Closure $callback)
 * @method static \GregPriday\LaravelRetry\Retry maxRetries(int $retries)
 * @method static \GregPriday\LaravelRetry\Retry timeout(int $seconds)
 * @method static \GregPriday\LaravelRetry\Retry withStrategy(\GregPriday\LaravelRetry\Contracts\RetryStrategy $strategy)
 * @method static \GregPriday\LaravelRetry\Retry retryIf(\Closure $condition)
 * @method static \GregPriday\LaravelRetry\Retry retryUnless(\Closure $condition)
 * @method static \GregPriday\LaravelRetry\Retry withEventCallbacks(array $callbacks)
 * @method static \GregPriday\LaravelRetry\Retry withMetadata(array $metadata)
 *
 * @see \GregPriday\LaravelRetry\Retry
 */
class Retry extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \GregPriday\LaravelRetry\Retry::class;
    }
}
