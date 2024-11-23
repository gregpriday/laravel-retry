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
     * @param  RetryStrategy|null  $fallbackStrategy  Strategy to use when no retry headers are present
     * @param  int  $maxDelay  Maximum delay to allow from headers (in seconds)
     */
    public function __construct(
        protected ?RetryStrategy $fallbackStrategy = null,
        protected int $maxDelay = 300 // 5 minutes max delay
    ) {
        $this->fallbackStrategy ??= new ExponentialBackoffStrategy;
    }

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  float  $baseDelay  Base delay in seconds
     * @return int Delay in seconds
     */
    public function getDelay(int $attempt, float $baseDelay): int
    {
        // If no exception stored or not a RequestException, use fallback
        if (! $this->lastException || ! ($this->lastException instanceof RequestException)) {
            return min(
                $this->fallbackStrategy->getDelay($attempt, $baseDelay),
                $this->maxDelay
            );
        }

        $response = $this->getResponseFromException($this->lastException);
        if (! $response) {
            return min(
                $this->fallbackStrategy->getDelay($attempt, $baseDelay),
                $this->maxDelay
            );
        }

        // Try different retry headers in order of preference
        $delay = $this->getRetryAfterDelay($response)
            ?? $this->getRateLimitResetDelay($response)
            ?? $this->getRetryInDelay($response);

        if ($delay === null) {
            return min(
                $this->fallbackStrategy->getDelay($attempt, $baseDelay),
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
     * @return int|null The delay in seconds, or null if header not present/invalid
     */
    protected function getRetryAfterDelay(ResponseInterface $response): ?int
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

        // If it's a date, convert to seconds
        if (strtotime($header) !== false) {
            return max(0, strtotime($header) - time());
        }

        // Otherwise, it should be seconds
        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * Get delay from X-RateLimit-Reset header.
     *
     * @param  ResponseInterface  $response  The HTTP response
     * @return int|null The delay in seconds, or null if header not present/invalid
     */
    protected function getRateLimitResetDelay(ResponseInterface $response): ?int
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

        $resetTime = (int) $resetTime;

        // Some APIs send Unix timestamp
        if ($resetTime > time()) {
            return max(0, $resetTime - time());
        }

        // Others send seconds from now
        return max(0, $resetTime);
    }

    /**
     * Get delay from X-Retry-In header.
     *
     * @param  ResponseInterface  $response  The HTTP response
     * @return int|null The delay in seconds, or null if header not present/invalid
     */
    protected function getRetryInDelay(ResponseInterface $response): ?int
    {
        if (! $response->hasHeader('X-Retry-In')) {
            return null;
        }

        $headers = $response->getHeader('X-Retry-In');
        if (empty($headers)) {
            return null;
        }

        $delay = trim($headers[0]);

        return is_numeric($delay) ? max(0, (int) $delay) : null;
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
        if (! $this->fallbackStrategy->shouldRetry($attempt, $maxAttempts, $lastException)) {
            return false;
        }

        // If no exception or not a RequestException, defer to fallback strategy
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
     * Get the fallback strategy being used.
     *
     * @return RetryStrategy The fallback strategy
     */
    public function getFallbackStrategy(): RetryStrategy
    {
        return $this->fallbackStrategy;
    }

    /**
     * Get the maximum delay value.
     *
     * @return int Maximum delay in seconds
     */
    public function getMaxDelay(): int
    {
        return $this->maxDelay;
    }
}
