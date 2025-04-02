<?php

namespace GregPriday\LaravelRetry\Strategies;

use Closure;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Throwable;

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
     * @param  RetryStrategy  $innerStrategy  The base strategy to wrap
     * @param  array<string, mixed>  $options  Custom options for retry behavior
     */
    public function __construct(RetryStrategy $innerStrategy, array $options = [])
    {
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
    public function getDelay(int $attempt, float $baseDelay): float
    {
        if ($this->delayCallback !== null) {
            return ($this->delayCallback)($attempt, $baseDelay, $this->options);
        }

        return $this->innerStrategy->getDelay($attempt, $baseDelay);
    }
}
