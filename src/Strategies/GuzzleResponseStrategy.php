<?php

namespace GregPriday\LaravelRetry\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class GuzzleResponseStrategy implements RetryStrategy
{
    /**
     * Store the last exception for delay calculation
     */
    private ?Throwable $lastException = null;

    /**
     * Create a new Guzzle response strategy.
     *
     * @param  RetryStrategy|null  $innerStrategy  Strategy to use when no retry headers are present
     * @param  float  $maxDelay  Maximum delay to allow from headers (in seconds)
     */
    public function __construct(
        protected ?RetryStrategy $innerStrategy = null,
        protected float $maxDelay = 300.0 // 5 minutes max delay
    ) {
        $this->innerStrategy ??= new ExponentialBackoffStrategy;
    }

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  float  $baseDelay  Base delay in seconds (can be float)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt, float $baseDelay): float
    {
        // If no exception stored or not a RequestException, use inner strategy
        if (! $this->lastException || ! ($this->lastException instanceof RequestException)) {
            return min(
                $this->innerStrategy->getDelay($attempt, $baseDelay),
                $this->maxDelay
            );
        }

        $response = $this->getResponseFromException($this->lastException);
        if (! $response) {
            return min(
                $this->innerStrategy->getDelay($attempt, $baseDelay),
                $this->maxDelay
            );
        }

        // Try different retry headers in order of preference
        $delay = $this->getRetryAfterDelay($response)
            ?? $this->getRateLimitResetDelay($response)
            ?? $this->getRetryInDelay($response);

        if ($delay === null) {
            return min(
                $this->innerStrategy->getDelay($attempt, $baseDelay),
                $this->maxDelay
            );
        }

        // Ensure delay doesn't exceed maximum
        return min($delay, $this->maxDelay);
    }

    /**
     * Get delay from Retry-After header.
     *
     * @param  ResponseInterface  $response  The HTTP response
     * @return float|null The delay in seconds, or null if header not present/invalid
     */
    protected function getRetryAfterDelay(ResponseInterface $response): ?float
    {
        if (! $response->hasHeader('Retry-After')) {
            return null;
        }

        $headers = $response->getHeader('Retry-After');
        if (empty($headers)) {
            return null;
        }

        $header = trim($headers[0]);
        if (empty($header)) {
            return null;
        }

        // If it's numeric, treat it as seconds directly
        if (is_numeric($header)) {
            return (float) $header;
        }

        // If it's a date, convert to seconds, ensuring UTC interpretation
        $timestamp = strtotime($header.' UTC');
        if ($timestamp === false) {
            return null;
        }

        return max(0.0, (float) ($timestamp - time()));
    }

    /**
     * Get delay from X-RateLimit-Reset header.
     *
     * @param  ResponseInterface  $response  The HTTP response
     * @return float|null The delay in seconds, or null if header not present/invalid
     */
    protected function getRateLimitResetDelay(ResponseInterface $response): ?float
    {
        if (! $response->hasHeader('X-RateLimit-Reset')) {
            return null;
        }

        $headers = $response->getHeader('X-RateLimit-Reset');
        if (empty($headers)) {
            return null;
        }

        $resetTime = trim($headers[0]);
        if (! is_numeric($resetTime)) {
            return null;
        }

        // Always treat as Unix timestamp
        $resetTimestamp = (int) $resetTime;

        return max(0.0, (float) ($resetTimestamp - time()));
    }

    /**
     * Get delay from X-Retry-In header.
     *
     * @param  ResponseInterface  $response  The HTTP response
     * @return float|null The delay in seconds, or null if header not present/invalid
     */
    protected function getRetryInDelay(ResponseInterface $response): ?float
    {
        if (! $response->hasHeader('X-Retry-In')) {
            return null;
        }

        $headers = $response->getHeader('X-Retry-In');
        if (empty($headers)) {
            return null;
        }

        $delay = trim($headers[0]);

        return is_numeric($delay) ? max(0.0, (float) $delay) : null;
    }

    /**
     * Determine if another retry attempt should be made.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  int  $maxAttempts  Maximum number of attempts allowed
     * @param  Throwable|null  $lastException  The last exception that occurred
     * @return bool Whether to retry the operation
     */
    public function shouldRetry(int $attempt, int $maxAttempts, ?Throwable $lastException = null): bool
    {
        // Store the exception for use in getDelay
        $this->lastException = $lastException;

        // First check if we've exceeded max attempts
        if (! $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException)) {
            return false;
        }

        // If no exception or not a RequestException, defer to inner strategy
        if (! $lastException instanceof RequestException) {
            return true;
        }

        $response = $this->getResponseFromException($lastException);
        if (! $response) {
            return true;
        }

        $statusCode = $response->getStatusCode();

        // Don't retry on client errors unless they're rate limit related
        if ($statusCode >= 400 && $statusCode < 500) {
            return $this->isRateLimitResponse($response) || $this->hasRetryAfterHeader($response);
        }

        // Retry on server errors
        return $statusCode >= 500;
    }

    /**
     * Get the response from a RequestException if available.
     *
     * @param  Throwable  $exception  The exception to extract response from
     * @return ResponseInterface|null The response if available
     */
    protected function getResponseFromException(Throwable $exception): ?ResponseInterface
    {
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            return $exception->getResponse();
        }

        return null;
    }

    /**
     * Check if the response indicates a rate limit.
     *
     * @param  ResponseInterface  $response  The HTTP response
     * @return bool Whether the response indicates a rate limit
     */
    protected function isRateLimitResponse(ResponseInterface $response): bool
    {
        $rateLimitHeaders = [
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
            'RateLimit-Remaining',
            'RateLimit-Reset',
        ];

        foreach ($rateLimitHeaders as $header) {
            if ($response->hasHeader($header)) {
                return true;
            }
        }

        return $response->getStatusCode() === 429;
    }

    /**
     * Check if response has any form of retry header.
     *
     * @param  ResponseInterface  $response  The HTTP response
     * @return bool Whether the response has any retry headers
     */
    protected function hasRetryAfterHeader(ResponseInterface $response): bool
    {
        $retryHeaders = [
            'Retry-After',
            'X-Retry-After',
            'X-Retry-In',
        ];

        foreach ($retryHeaders as $header) {
            if ($response->hasHeader($header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the inner strategy being used.
     *
     * @return RetryStrategy The inner strategy
     */
    public function getInnerStrategy(): RetryStrategy
    {
        return $this->innerStrategy;
    }

    /**
     * Get the maximum delay value.
     *
     * @return float Maximum delay in seconds
     */
    public function getMaxDelay(): float
    {
        return $this->maxDelay;
    }
}
