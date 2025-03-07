<?php

namespace GregPriday\LaravelRetry\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline send(mixed $passable)
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline through(array|mixed $pipes)
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline via(string $method)
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline maxRetries(int $retries)
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline retryDelay(int $seconds)
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline timeout(int $seconds)
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline withStrategy(\GregPriday\LaravelRetry\Contracts\RetryStrategy $strategy)
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline withProgress(\Closure $callback)
 * @method static \GregPriday\LaravelRetry\Pipeline\RetryablePipeline withExceptionManager(\GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager $manager)
 * @method static mixed then(\Closure $destination)
 *
 * @see \GregPriday\LaravelRetry\Pipeline\RetryablePipeline
 */
class RetryablePipeline extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return GregPriday\LaravelRetry\Pipeline\RetryablePipeline::class;
    }
}
