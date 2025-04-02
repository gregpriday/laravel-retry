<?php

namespace GregPriday\LaravelRetry\Strategies;

use Closure;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

/**
 * CustomOptionsStrategy allows for flexible, customized retry behavior using closures.
 *
 * This strategy provides a way to define specialized retry logic without creating
 * full strategy classes. It supports custom callbacks for determining both when to retry
 * and how long to delay between attempts, with additional options passed to the callbacks
 * for contextual decision-making.
 */
class CustomOptionsStrategy implements RetryStrategy
{
    /**
     * @var array<string, mixed>
     */
    protected array $options = [];

    protected ?Closure $shouldRetryCallback = null;

    protected ?Closure $delayCallback = null;

    protected RetryStrategy $innerStrategy;

    /**
     * Create a new custom options strategy.
     *
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @param  RetryStrategy  $innerStrategy  The base strategy to wrap
     * @param  array<string, mixed>  $options  Custom options for retry behavior
     */
    public function __construct(
        protected float $baseDelay,
        RetryStrategy $innerStrategy,
        array $options = []
    ) {
        $this->innerStrategy = $innerStrategy;
        $this->options = $options;
    }

    /**
     * Set a custom callback to determine if retry should occur.
     *
     * @return $this
     */
    public function withShouldRetryCallback(Closure $callback): self
    {
        $this->shouldRetryCallback = $callback;

        return $this;
    }

    /**
     * Set a custom callback to determine delay between retries.
     *
     * @return $this
     */
    public function withDelayCallback(Closure $callback): self
    {
        $this->delayCallback = $callback;

        return $this;
    }

    /**
     * Get an option value by key.
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set an option value.
     *
     * @return $this
     */
    public function setOption(string $key, mixed $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldRetry(int $attempt, int $maxAttempts, ?Throwable $lastException = null): bool
    {
        if ($this->shouldRetryCallback !== null) {
            return ($this->shouldRetryCallback)($attempt, $maxAttempts, $lastException, $this->options);
        }

        return $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException);
    }

    /**
     * {@inheritdoc}
     */
    public function getDelay(int $attempt): float
    {
        if ($this->delayCallback !== null) {
            return ($this->delayCallback)($attempt, $this->baseDelay, $this->options);
        }

        return $this->innerStrategy->getDelay($attempt);
    }
}
