# Laravel Retry

A robust and flexible retry mechanism for Laravel applications to handle transient failures in HTTP requests, database queries, API calls, and any other potentially unstable operations. The package provides a host of powerful features to automate and customize retry logic in your application.

## Features

- **Multiple Retry Strategies**
    - Exponential Backoff (with optional jitter)
    - Linear Backoff
    - Fixed Delay
    - Decorrelated Jitter (AWS-style)
    - Fibonacci Backoff
    - Rate Limiting
    - Circuit Breaker pattern
    - Total Operation Timeout
    - Response Content Analysis
    - Composable strategies for complex scenarios

- **Promise-like Result Handling**
    - Chain success/failure handlers
    - Handle errors gracefully
    - Access attempt history
    - Fluent interface

- **Built-in Exception Handling**
    - Automatic detection of retryable errors
    - Extensible exception handler system
    - Built-in support for Guzzle exceptions
    - Custom error pattern matching

- **Observability & Instrumentation**
    - Event dispatching at key retry lifecycle points
    - Monitor retry attempts, successes, and failures
    - Easy integration with monitoring systems
    - Custom event callbacks for fine-grained control

- **Pipeline Integration**
    - Retryable Pipeline for complex operation chains
    - Pipe-specific retry settings
    - Progress tracking during pipeline execution

- **Comprehensive Configuration**
    - Configurable retry attempts
    - Adjustable delays and timeouts
    - Custom retry conditions
    - Progress tracking and logging hooks

---

## Installation

Install the package via Composer:

```bash
composer require gregpriday/laravel-retry
```

Then publish the configuration file:

```bash
php artisan vendor:publish --tag="retry-config"
```

This publishes a `config/retry.php` file where you can adjust package-specific settings.

---

## Basic Usage

### 1. Simple Retry Operation

```php
use GregPriday\LaravelRetry\Facades\Retry;

$result = Retry::run(function () {
    // Potentially flaky operation, e.g. an API call
    return Http::get('https://api.example.com/data');
})->then(function ($response) {
    // Handle successful response
    return $response->json();
})->catch(function (Throwable $e) {
    // Handle complete failure after all retries
    Log::error('API call failed', ['error' => $e->getMessage()]);
    return null;
});
```

In this example:

- **`run()`** initiates a retryable operation.
- **`then()`** runs if all retries succeed.
- **`catch()`** runs if the operation ultimately fails after exhausting retries.

---

### 2. Using Dependency Injection

```php
use GregPriday\LaravelRetry\Retry;

class ApiService
{
    public function __construct(
        private Retry $retry
    ) {}

    public function fetchData()
    {
        return $this->retry
            ->maxRetries(5)
            ->retryDelay(1)
            ->run(fn() => Http::get('https://api.example.com/data'))
            ->value(); // Throws on error; returns successful value otherwise
    }
}
```

You can inject the `Retry` service anywhere in your Laravel application. This lets you configure and run retries easily within any class or service.

---

### 3. Configuring Retry Behavior

```php
Retry::make()
    ->maxRetries(5)                    // Maximum retry attempts
    ->retryDelay(2)                    // Base delay in seconds between attempts
    ->timeout(30)                      // Timeout per attempt (in seconds)
    ->withProgress(function ($message) {
        // Optional: track progress or log messages
        Log::info($message);
    })
    ->run(function () {
        return DB::table('users')->get();
    });
```

- **`maxRetries()`**: Max number of attempts.
- **`retryDelay()`**: Base delay between attempts (many strategies will grow this delay over time).
- **`timeout()`**: How long each operation attempt is allowed to run.
- **`withProgress()`**: Callback triggered on each failed attempt before retrying.

---

### 4. Custom Retry Conditions

You can define your own condition logic to decide if a given exception is retryable:

```php
// Example 1: Use retryIf
Retry::make()
    ->retryIf(function (Throwable $e, array $context) {
        // Only retry for RateLimitException if attempts remain
        return $e instanceof RateLimitException && 
               $context['remaining_attempts'] > 0;
    })
    ->run(function () {
        return $api->fetch();
    });

// Example 2: Use retryUnless
Retry::make()
    ->retryUnless(function (Throwable $e, array $context) {
        // Do not retry if we've seen the same error multiple times
        return $context['attempt'] >= 2;
    })
    ->run(function () {
        return $api->fetch();
    });
```

`$context` includes information such as `attempt`, `max_retries`, `remaining_attempts`, and `exception_history`.

---

## Retry Strategies

`laravel-retry` supports multiple strategies out of the box:

### 1. Exponential Backoff (Default)

Increases delay exponentially between retries:

```php
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new ExponentialBackoffStrategy(
    multiplier: 2.0,   // Each subsequent delay = delay * 2
    maxDelay: 30,      // Max delay in seconds
    withJitter: true   // Add randomness to reduce thundering herd
);

Retry::withStrategy($strategy)->run(fn() => doSomething());
```

### 2. Linear Backoff

Increases delay linearly:

```php
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;

$strategy = new LinearBackoffStrategy(
    increment: 5,   // Add 5 seconds more each retry
    maxDelay: 30
);
```

### 3. Fixed Delay

Uses the same delay for all attempts:

```php
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;

$strategy = new FixedDelayStrategy(
    withJitter: true,        // Apply jitter if desired
    jitterPercent: 0.2       // ±20% jitter
);
```

### 4. Decorrelated Jitter

Implements AWS's "Exponential Backoff and Jitter":

```php
use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;

$strategy = new DecorrelatedJitterStrategy(
    maxDelay: 30,    // Maximum delay
    minFactor: 1.0,  // Minimum multiplier
    maxFactor: 3.0   // Maximum multiplier
);
```

### 5. Fibonacci Backoff

Increases delay according to the Fibonacci sequence (1, 1, 2, 3, 5, 8, 13...):

```php
use GregPriday\LaravelRetry\Strategies\FibonacciBackoffStrategy;

$strategy = new FibonacciBackoffStrategy(
    maxDelay: 30,      // Maximum delay in seconds
    withJitter: true   // Add randomness to avoid thundering herd
);

Retry::withStrategy($strategy)->run(fn() => doSomething());
```

Fibonacci backoff grows more gradually than exponential backoff, making it suitable for systems where you want a more moderate increase in delays between attempts.

### 6. Rate Limit Strategy

Limit the total number of retry attempts within a time window:

```php
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;

$strategy = new RateLimitStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    maxAttempts: 100, // Maximum attempts in timeWindow
    timeWindow: 60    // in seconds
);
```

### 7. Circuit Breaker Strategy

Use the Circuit Breaker pattern to stop retrying after repeated failures:

```php
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;

$strategy = new CircuitBreakerStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    failureThreshold: 5,  // Open circuit after 5 failures
    resetTimeout: 60      // Try again after 60 seconds
);
```

### 8. Total Timeout Strategy

Enforces a maximum total time for the entire retry operation, including all delay periods:

```php
use GregPriday\LaravelRetry\Strategies\TotalTimeoutStrategy;

$strategy = new TotalTimeoutStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    totalTimeout: 60  // Maximum 60 seconds for the entire operation
);
```

This strategy is useful for operations that must complete within a strict time limit, regardless of the number of retries needed. It will adjust delays to fit within the remaining time or terminate retry attempts if the total timeout is reached.

### 9. Response Content Strategy

Analyzes response body content to determine if a retry is needed, even when HTTP status codes look normal:

```php
use GregPriday\LaravelRetry\Strategies\ResponseContentStrategy;

$strategy = new ResponseContentStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    retryableContentPatterns: [
        '/temporarily unavailable/i',
        '/server busy/i'
    ],
    retryableErrorCodes: [
        'RATE_LIMITED',
        'TRY_AGAIN_LATER',
        'RESOURCE_EXHAUSTED'
    ],
    errorCodePaths: ['error.code', 'error_code', 'status']
);

// Fluent interface for adding patterns/codes:
$strategy->withContentPatterns(['/service unavailable/i'])
         ->withErrorCodes(['SERVER_BUSY'])
         ->withErrorCodePaths(['custom.error.type']);

// Custom content checker:
$strategy->withContentChecker(function ($response) {
    // Implement custom logic to determine if response needs retry
    $body = $response->body();
    return strpos($body, 'Please retry') !== false;
});
```

This strategy is especially useful for APIs that return 200 OK status codes but include error indicators in the response body.

---

## HTTP Client Integration

`laravel-retry` provides seamless integration with Laravel's HTTP client through a `robustRetry` macro:

```php
use Illuminate\Support\Facades\Http;
use GregPriday\LaravelRetry\Strategies\ResponseContentStrategy;

// Basic usage
$response = Http::robustRetry(3)
    ->get('https://api.example.com/data');

// Advanced configuration
$response = Http::robustRetry(
    maxAttempts: 5,
    strategy: new ResponseContentStrategy(
        innerStrategy: new FibonacciBackoffStrategy(),
        retryableErrorCodes: ['RATE_LIMITED', 'SERVER_BUSY']
    ),
    retryDelay: 2,
    timeout: 30,
    throw: true
)->post('https://api.example.com/data', [
    'key' => 'value'
]);
```

---

## Contributing & Support

- **Issues**: Please open issues on GitHub if you encounter bugs or need further help.
- **Pull Requests**: Contributions are welcome! Submit a PR for any improvements, bug fixes, or new features.

We hope **Laravel Retry** helps you build more resilient applications!

---

**Thank you for using Laravel Retry!** If you have any questions, comments, or suggestions, feel free to open an issue or contribute on GitHub.
