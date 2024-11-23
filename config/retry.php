<?php

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
    |
    */
    'delay' => env('RETRY_DELAY', 5),

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
];
