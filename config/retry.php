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
    | This option controls the default retry strategy that will be used when
    | Laravel Retry is used without specifying a strategy. The options for
    | this strategy will be looked up in the 'strategies' array below.
    |
    | Supported built-in aliases: "exponential-backoff", "linear-backoff",
    | "linear-delay", "fixed-delay", "constant-delay", "fibonacci-backoff",
    | "decorrelated-jitter", "rate-limit", "total-timeout",
    | "circuit-breaker", "guzzle-response", "response-content",
    | "custom-options", "callback-retry"
    |
    */
    'default' => env('RETRY_STRATEGY', 'exponential-backoff'),

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

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Settings
    |--------------------------------------------------------------------------
    |
    | These options define the default settings for the circuit breaker strategy.
    | Named circuit breakers can be configured below to provide service-specific
    | circuit breaker configurations.
    |
    */
    'circuit_breaker' => [
        'default' => [
            // Number of failures before opening circuit
            'failure_threshold' => env('RETRY_CB_FAILURE_THRESHOLD', 5),

            // Seconds before attempting reset (half-open)
            'reset_timeout' => env('RETRY_CB_RESET_TIMEOUT', 60.0),

            // Cache TTL in minutes (default 1 day)
            'cache_ttl' => env('RETRY_CB_CACHE_TTL', 1440),

            // Whether to fail open (block requests) when cache operations fail
            'fail_open_on_cache_error' => env('RETRY_CB_FAIL_OPEN', false),

            // Inner retry strategy to use (kebab-case alias from 'strategies' section)
            'inner_strategy' => env('RETRY_CB_INNER_STRATEGY', 'exponential-backoff'),

            // Legacy support: inner_config still supported but will be merged with
            // the options from the strategies section
            'inner_config' => [
                'baseDelay'     => env('RETRY_CB_INNER_BASE_DELAY', 0.1),
                'multiplier'    => env('RETRY_CB_INNER_MULTIPLIER', 2),
                'jitterPercent' => env('RETRY_CB_INNER_JITTER', 0.1),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Named Circuit Breakers
        |--------------------------------------------------------------------------
        |
        | Define named circuit breakers for different services with specific
        | settings. Each key represents a service name, and the values override
        | the default settings from above.
        |
        | Example usage:
        | $retry = new Retry();
        | $retry->withCircuitBreaker('api_service')->run(function() { ... });
        |
        */
        'services' => [
            'api_service' => [
                'failure_threshold' => 3,
                'reset_timeout'     => 30.0,
                'cache_key'         => 'circuit_breaker_api_service', // Explicit cache key is recommended
            ],

            'database_service' => [
                'failure_threshold' => 2,
                'reset_timeout'     => 10.0,
                'cache_key'         => 'circuit_breaker_database',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy Defaults
    |--------------------------------------------------------------------------
    |
    | Default configuration for each retry strategy, keyed by kebab-case alias.
    | These options are passed to the strategy's constructor.
    |
    */
    'strategies' => [
        'exponential-backoff' => [
            'baseDelay'     => (float) env('RETRY_EXPONENTIAL_BASE_DELAY', 0.1),
            'multiplier'    => (float) env('RETRY_EXPONENTIAL_MULTIPLIER', 2.0),
            'jitterPercent' => (float) env('RETRY_EXPONENTIAL_JITTER', 0.1),
        ],

        'linear-backoff' => [
            'baseDelay' => (float) env('RETRY_LINEAR_BASE_DELAY', 0.5),
            'increment' => (float) env('RETRY_LINEAR_INCREMENT', 0.5),
        ],

        'fixed-delay' => [
            'baseDelay' => (float) env('RETRY_FIXED_DELAY', 1.0),
        ],

        'constant-delay' => [
            'baseDelay' => (float) env('RETRY_CONSTANT_DELAY', 1.0),
        ],

        'fibonacci-backoff' => [
            'baseDelay'     => (float) env('RETRY_FIBONACCI_BASE_DELAY', 0.1),
            'jitterPercent' => (float) env('RETRY_FIBONACCI_JITTER', 0.1),
        ],

        'decorrelated-jitter' => [
            'baseDelay' => (float) env('RETRY_DECORRELATED_BASE_DELAY', 0.1),
            'maxDelay'  => (float) env('RETRY_DECORRELATED_MAX_DELAY', 60.0),
        ],

        'guzzle-response' => [
            'baseDelay' => (float) env('RETRY_GUZZLE_BASE_DELAY', 1.0),
            'maxDelay'  => (float) env('RETRY_GUZZLE_MAX_DELAY', 300.0),
            // Inner strategy is handled by the factory
        ],

        'response-content' => [
            // Options will be loaded from the response_content section
            // Inner strategy is handled by the factory using the default strategy
        ],

        'rate-limit' => [
            'maxAttempts' => (int) env('RETRY_RATE_LIMIT_MAX_ATTEMPTS', 100),
            'timeWindow'  => (int) env('RETRY_RATE_LIMIT_TIME_WINDOW', 60),
            'storageKey'  => env('RETRY_RATE_LIMIT_STORAGE_KEY', 'default-rate-limit'),
            // Inner strategy is handled by the factory
        ],

        'total-timeout' => [
            'totalTimeout' => (float) env('RETRY_TOTAL_TIMEOUT', 300),
            // Inner strategy is handled by the factory
        ],

        'circuit-breaker' => [
            // Options are loaded from the circuit_breaker section
        ],

        'custom-options' => [
            'baseDelay' => (float) env('RETRY_CUSTOM_BASE_DELAY', 1.0),
            // Additional options handled per use case
        ],

        'callback-retry' => [
            'baseDelay' => (float) env('RETRY_CALLBACK_BASE_DELAY', 1.0),
            // Callbacks must be provided when using this strategy
        ],
    ],
];
