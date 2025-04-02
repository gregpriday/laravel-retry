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
    | Default Retry Delay
    |--------------------------------------------------------------------------
    |
    | This value determines the base delay (in seconds) between retry attempts.
    | The actual delay will increase exponentially with each attempt.
    | Can be a float value for microsecond precision (e.g., 0.5 for 500ms).
    |
    */
    'delay' => env('RETRY_DELAY', 1.0),

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
