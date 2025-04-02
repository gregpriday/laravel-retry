<?php

namespace GregPriday\LaravelRetry\Pipeline;

use Closure;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline as BasePipeline;
use RuntimeException;

/**
 * A pipeline that wraps each pipe in a retry mechanism using the laravel-retry package.
 *
 * This class extends Laravel's Pipeline and allows for configurable retry behavior
 * at both the pipeline level and per-pipe level. Pipes can override retry settings
 * by defining public properties such as retryCount, retryStrategy, or timeout.
 */
class RetryablePipeline extends BasePipeline
{
    /** @var int Maximum number of retries for each pipe */
    protected int $maxRetries;

    /** @var int Operation timeout in seconds */
    protected int $timeout;

    /** @var ?RetryStrategy Retry strategy instance, null defaults to ExponentialBackoffStrategy */
    protected ?RetryStrategy $strategy = null;

    /** @var ?Closure Progress callback for pipe operations */
    protected ?Closure $progressCallback = null;

    /** @var ?ExceptionHandlerManager Custom exception handler manager */
    protected ?ExceptionHandlerManager $exceptionManager = null;

    /** @var array Additional patterns for retryable exceptions */
    protected array $additionalPatterns = [];

    /** @var array Additional exception types that should be retryable */
    protected array $additionalExceptions = [];

    /**
     * Create a new RetryablePipeline instance.
     */
    public function __construct(?Container $container = null)
    {
        parent::__construct($container);

        $this->maxRetries = 3;
        $this->timeout = 30;
    }

    /**
     * Set the maximum number of retries.
     *
     * @param  int  $retries  Number of retry attempts
     * @return $this
     */
    public function maxRetries(int $retries): self
    {
        $this->maxRetries = $retries;

        return $this;
    }

    /**
     * Set the operation timeout.
     *
     * @param  int  $seconds  Timeout in seconds
     * @return $this
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the retry strategy.
     *
     * @param  RetryStrategy  $strategy  Strategy instance
     * @return $this
     */
    public function withStrategy(RetryStrategy $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Set the progress callback.
     *
     * @param  ?Closure  $callback  Progress callback function
     * @return $this
     */
    public function withProgress(?Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    /**
     * Set the exception handler manager.
     *
     * @param  ExceptionHandlerManager  $manager  Exception handler manager
     * @return $this
     */
    public function withExceptionManager(ExceptionHandlerManager $manager): self
    {
        $this->exceptionManager = $manager;

        return $this;
    }

    /**
     * Set additional regex patterns for retryable exceptions.
     *
     * @param  array  $patterns  Array of regex patterns
     * @return $this
     */
    public function withAdditionalPatterns(array $patterns): self
    {
        $this->additionalPatterns = $patterns;

        return $this;
    }

    /**
     * Set additional exception types that should be retryable.
     *
     * @param  array  $exceptions  Array of exception class names
     * @return $this
     */
    public function withAdditionalExceptions(array $exceptions): self
    {
        $this->additionalExceptions = $exceptions;

        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.
     *
     * Each pipe is wrapped with a retry mechanism according to the pipeline's
     * or the pipe's own strategy.
     *
     * @param  Closure  $destination  The final callback to handle the passable
     * @return mixed The result of the pipeline execution
     *
     * @throws RuntimeException If a pipe is invalid
     */
    public function then(Closure $destination)
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            function ($next, $pipe) {
                return function ($passable) use ($pipe, $next) {
                    // Resolve the pipe instance if it's a class name
                    if (is_string($pipe)) {
                        $pipe = app($pipe);
                    }

                    // Determine pipe-specific overrides
                    $localMaxRetries = $this->maxRetries;
                    $localStrategy = $this->strategy;
                    $localTimeout = $this->timeout;
                    $localPatterns = $this->additionalPatterns;
                    $localExceptions = $this->additionalExceptions;

                    // Check for pipe-specific retry settings
                    if (is_object($pipe)) {
                        if (property_exists($pipe, 'retryCount')) {
                            $localMaxRetries = $pipe->retryCount;
                        }

                        if (property_exists($pipe, 'retryStrategy')) {
                            $localStrategy = $pipe->retryStrategy;
                        }

                        if (property_exists($pipe, 'timeout')) {
                            $localTimeout = $pipe->timeout;
                        }

                        if (property_exists($pipe, 'additionalPatterns')) {
                            $localPatterns = array_merge($localPatterns, $pipe->additionalPatterns);
                        }

                        if (property_exists($pipe, 'additionalExceptions')) {
                            $localExceptions = array_merge($localExceptions, $pipe->additionalExceptions);
                        }
                    }

                    // Create a new Retry instance with the appropriate settings
                    $retry = new Retry(
                        maxRetries: $localMaxRetries,
                        timeout: $localTimeout,
                        progressCallback: $this->progressCallback,
                        strategy: $localStrategy,
                        exceptionManager: $this->exceptionManager
                    );

                    // Run the pipe with retry logic
                    return $retry->run(
                        function () use ($pipe, $passable, $next) {
                            if (is_callable($pipe)) {
                                return $pipe($passable, $next);
                            }

                            if (is_object($pipe) && method_exists($pipe, '__invoke')) {
                                return $pipe($passable, $next);
                            }

                            if (is_object($pipe) && method_exists($pipe, 'handle')) {
                                return $pipe->handle($passable, $next);
                            }

                            throw new RuntimeException('Invalid pipeline element: pipe must be callable or have a handle method.');
                        },
                        $localPatterns,
                        $localExceptions
                    )->value();
                };
            },
            $destination
        );

        return $pipeline($this->passable);
    }
}
