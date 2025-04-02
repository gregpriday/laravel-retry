<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Maximum Retries
    |--------------------------------------------------------------------------
    |
    | This value determines the maximum number of times an operation will be
    | retried before giving up.
    |
    */
    'max_retries' => env('RETRY_MAX_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Default Operation Timeout
    |--------------------------------------------------------------------------
    |
    | This value determines the maximum time (in seconds) that each attempt
    | is allowed to run before timing out.
    |
    */
    'timeout' => env('RETRY_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Total Operation Timeout
    |--------------------------------------------------------------------------
    |
    | This value determines the maximum time (in seconds) that the entire
    | retry operation is allowed to run, including all retry attempts.
    | When used with TotalTimeoutStrategy, this overrides the regular timeout.
    | Can be a float value for microsecond precision (e.g., 10.5 for 10.5 seconds).
    |
    */
    'total_timeout' => env('RETRY_TOTAL_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Default Strategy
    |--------------------------------------------------------------------------
    |
    | This setting allows you to configure the default retry strategy used
    | when no strategy is explicitly specified. You can specify the class and
    | constructor parameters.
    |
    */
    'default_strategy' => [
        // The strategy class to use by default
        'class' => env(
            'RETRY_DEFAULT_STRATEGY',
            \GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy::class
        ),

        // Constructor parameters for the strategy
        // The baseDelay fallback order is:
        // 1. baseDelay in options (if explicitly set here)
        // 2. Legacy retry.delay config (for backwards compatibility, if it exists)
        // 3. Strategy's own default baseDelay value
        'options' => [
            'multiplier'    => 2.0,
            'maxDelay'      => null,
            'withJitter'    => false,
            'jitterPercent' => 0.2,
            'baseDelay'     => 1.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Dispatching
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will dispatch Laravel events at key points
    | in the retry lifecycle (before retry, on success, on failure).
    |
    | This can be used for monitoring, logging, or alerting.
    |
    */
    'dispatch_events' => env('RETRY_DISPATCH_EVENTS', true),

    /*
    |--------------------------------------------------------------------------
    | Handler Paths
    |--------------------------------------------------------------------------
    |
    | Additional paths where retry handlers can be found. Package handlers and
    | application handlers (app/Exceptions/Retry/Handlers) are included by default.
    |
    */
    'handler_paths' => [
        // Add custom paths here
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Content Strategy Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the ResponseContentStrategy for inspecting response bodies
    | to determine if a retry should be attempted.
    |
    */
    'response_content' => [
        // Default retryable error codes in response body
        'retryable_error_codes' => [
            'TEMPORARY_ERROR',
            'SERVER_BUSY',
            'RATE_LIMITED',
            'TRY_AGAIN_LATER',
            'RESOURCE_EXHAUSTED',
            'SERVICE_UNAVAILABLE',
            'INTERNAL_ERROR',
            'TEMPORARILY_UNAVAILABLE',
        ],

        // Default paths to check for error codes in JSON responses
        'error_code_paths' => [
            'error.code',
            'error_code',
            'code',
            'status',
            'error.type',
            'errorCode',
            'error.status',
        ],

        // Default regex patterns to match in response body
        'retryable_content_patterns' => [
            '/temporarily unavailable/i',
            '/server busy/i',
            '/try again later/i',
            '/rate limit(ed)?/i',
            '/too many requests/i',
            '/service unavailable/i',
            '/internal (server )?error/i',
            '/timeout/i',
            '/throttl(ed|ing)/i',
        ],
    ],
];
