<?php

namespace GregPriday\LaravelRetry\Strategies;

use Closure;
use Exception;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Illuminate\Http\Client\Response;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * ResponseContentStrategy inspects HTTP response content to determine if a retry is needed.
 *
 * This strategy examines the body of HTTP responses (even successful ones with 200 status codes)
 * to detect temporary errors based on content patterns or JSON error codes. It's ideal for APIs
 * that signal transient failures within the response body rather than status codes.
 */
class ResponseContentStrategy implements RetryStrategy
{
    /**
     * Store the last exception for delay calculation
     */
    private ?Throwable $lastException = null;

    /**
     * Create a new response content strategy.
     *
     * @param  RetryStrategy  $innerStrategy  The wrapped retry strategy
     * @param  array  $retryableContentPatterns  Array of regex patterns to match in response body
     * @param  array  $retryableErrorCodes  Array of error codes in JSON responses that indicate retryable errors
     * @param  string[]  $errorCodePaths  JSON paths to check for error codes (e.g. 'error.code', 'status')
     * @param  Closure|null  $retryableContentChecker  Custom function to determine if response content is retryable
     */
    public function __construct(
        protected RetryStrategy $innerStrategy,
        protected array $retryableContentPatterns = [],
        protected array $retryableErrorCodes = [],
        protected array $errorCodePaths = ['error.code', 'error_code', 'code', 'status'],
        protected ?Closure $retryableContentChecker = null
    ) {}

    /**
     * Calculate the delay for the next retry attempt.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @return float Delay in seconds (can have microsecond precision)
     */
    public function getDelay(int $attempt): float
    {
        return $this->innerStrategy->getDelay($attempt);
    }

    /**
     * Determine if another retry attempt should be made.
     *
     * @param  int  $attempt  Current attempt number (0-based)
     * @param  int  $maxAttempts  Maximum number of attempts allowed
     * @param  Throwable|null  $lastException  The last exception that occurred
     */
    public function shouldRetry(int $attempt, int $maxAttempts, ?Throwable $lastException = null): bool
    {
        // Store the exception for potential use in extractResponse
        $this->lastException = $lastException;

        // First check if we've exceeded max attempts
        if ($attempt >= $maxAttempts) {
            return false;
        }

        // If no exception occurred or no response to analyze, defer to inner strategy
        $response = $this->extractResponse($lastException);
        if (! $response) {
            return $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException);
        }

        // Check if response content indicates a retry is needed
        if ($this->isContentRetryable($response)) {
            return true;
        }

        // If we've gotten here and no retry is indicated by content, defer to inner strategy
        return $this->innerStrategy->shouldRetry($attempt, $maxAttempts, $lastException);
    }

    /**
     * Extract a response object from an exception, if available.
     *
     * @param  Throwable|null  $exception  The exception that occurred
     * @return mixed Response object or null if none found
     */
    protected function extractResponse(?Throwable $exception): mixed
    {
        if (! $exception) {
            return null;
        }

        // Check for Guzzle exceptions which might contain a response
        $response = null;

        // Access the private property via reflection for RequestException
        if (class_exists('GuzzleHttp\Exception\RequestException') &&
            $exception instanceof \GuzzleHttp\Exception\RequestException) {
            if (method_exists($exception, 'getResponse')) {
                $response = $exception->getResponse();
                if ($response) {
                    return $response;
                }
            }
        }

        // Handle Laravel HTTP client exceptions
        if (class_exists('Illuminate\Http\Client\RequestException') &&
            $exception instanceof \Illuminate\Http\Client\RequestException) {
            if (method_exists($exception, 'response')) {
                $response = $exception->response();
                if ($response) {
                    return $response;
                }
            }
        }

        // Try common properties that might contain a response
        $possibleProperties = ['response', 'httpResponse', 'clientResponse'];
        foreach ($possibleProperties as $property) {
            if (property_exists($exception, $property)) {
                $response = $exception->{$property};
                if ($response) {
                    return $response;
                }
            }
        }

        return null;
    }

    /**
     * Determine if the response content indicates a retry is needed.
     *
     * @param  mixed  $response  The response object
     * @return bool Whether the response content indicates a retry is needed
     */
    protected function isContentRetryable(mixed $response): bool
    {
        // Custom checker has highest priority
        if ($this->retryableContentChecker !== null) {
            return ($this->retryableContentChecker)($response);
        }

        // Get the response body content
        $content = $this->getResponseBody($response);
        if (! $content) {
            return false;
        }

        // Check regex patterns against raw content
        foreach ($this->retryableContentPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        // Try to parse JSON content
        $json = $this->parseJson($content);
        if ($json !== null) {
            // Check for specific error codes in various paths
            foreach ($this->errorCodePaths as $path) {
                $errorCode = $this->getNestedValue($json, $path);
                if ($errorCode !== null && in_array($errorCode, $this->retryableErrorCodes, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the response body as a string.
     *
     * @param  mixed  $response  The response object
     * @return string|null The response body or null if not available
     */
    protected function getResponseBody(mixed $response): ?string
    {
        // Handle Laravel HTTP client response
        if ($response instanceof Response) {
            return $response->body();
        }

        // Handle PSR-7 response
        if ($response instanceof ResponseInterface) {
            $body = $response->getBody();
            try {
                $body->rewind(); // Ensure we're at the start of the stream

                return $body->getContents();
            } catch (Exception $e) {
                // Handle non-seekable streams or other stream errors
                // Try to get what we can from the stream
                $content = (string) $body;

                return ! empty($content) ? $content : null;
            }
        }

        // Handle response with getBody() method
        if (method_exists($response, 'getBody')) {
            $body = $response->getBody();
            if (is_string($body)) {
                return $body;
            }
            if (is_object($body) && method_exists($body, 'getContents')) {
                try {
                    $body->rewind();

                    return $body->getContents();
                } catch (Exception $e) {
                    // Handle stream errors
                    $content = (string) $body;

                    return ! empty($content) ? $content : null;
                }
            }
        }

        // Handle response with getContent() method
        if (method_exists($response, 'getContent')) {
            return $response->getContent();
        }

        // Handle response with content property
        if (property_exists($response, 'content')) {
            return $response->content;
        }

        // Handle response with body property
        if (property_exists($response, 'body')) {
            $body = $response->body;
            if (is_string($body)) {
                return $body;
            }
        }

        // If we can cast to string, try that
        if (is_object($response) && method_exists($response, '__toString')) {
            return (string) $response;
        }

        return null;
    }

    /**
     * Parse JSON string into an array/object.
     *
     * @param  string  $content  JSON string
     * @return mixed Parsed JSON or null if invalid
     */
    protected function parseJson(string $content): mixed
    {
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
    }

    /**
     * Get a nested value from an array using a dot notation path.
     *
     * @param  array  $array  The array to search
     * @param  string  $path  Path using dot notation (e.g. 'error.code')
     * @return mixed The value or null if not found
     */
    protected function getNestedValue(array $array, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (! is_array($current) || ! isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Set a custom content checker function.
     *
     * @param  Closure  $checker  Function(mixed $response): bool
     */
    public function withContentChecker(Closure $checker): self
    {
        $this->retryableContentChecker = $checker;

        return $this;
    }

    /**
     * Add retryable content patterns.
     *
     * @param  array  $patterns  Array of regex patterns
     */
    public function withContentPatterns(array $patterns): self
    {
        $this->retryableContentPatterns = array_merge($this->retryableContentPatterns, $patterns);

        return $this;
    }

    /**
     * Add retryable error codes.
     *
     * @param  array  $errorCodes  Array of error codes
     */
    public function withErrorCodes(array $errorCodes): self
    {
        $this->retryableErrorCodes = array_merge($this->retryableErrorCodes, $errorCodes);

        return $this;
    }

    /**
     * Set error code paths to check in JSON responses.
     *
     * @param  array  $paths  Array of paths using dot notation
     */
    public function withErrorCodePaths(array $paths): self
    {
        $this->errorCodePaths = $paths;

        return $this;
    }

    /**
     * Get the inner strategy.
     */
    public function getInnerStrategy(): RetryStrategy
    {
        return $this->innerStrategy;
    }
}
