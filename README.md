# Laravel Retry

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gregpriday/laravel-retry.svg?style=flat-square)](https://packagist.org/packages/gregpriday/laravel-retry)
[![Total Downloads](https://img.shields.io/packagist/dt/gregpriday/laravel-retry.svg?style=flat-square)](https://packagist.org/packages/gregpriday/laravel-retry)
[![License](https://img.shields.io/packagist/l/gregpriday/laravel-retry.svg?style=flat-square)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg?style=flat-square)](composer.json)
[![Laravel Version](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg?style=flat-square)](composer.json)
[![Code Style](https://img.shields.io/badge/code%20style-laravel-brightgreen.svg?style=flat-square)](https://laravel.com/docs/pint)

A powerful, flexible, and deeply integrated retry system for Laravel applications that goes beyond simple retry loops. This package provides sophisticated retry strategies, deep Laravel integration, and comprehensive observability to make your applications more resilient to transient failures.

## Introduction

In modern web applications, dealing with external services, APIs, and databases is commonplace. However, these interactions can fail due to temporary issues like network glitches, rate limits, or service unavailability. Laravel Retry provides a robust solution to handle these transient failures elegantly and efficiently.

What sets Laravel Retry apart:

- **Comprehensive Retry Strategies**: Beyond basic exponential backoff, offering sophisticated strategies like Circuit Breaker, Rate Limiting, AWS-style Decorrelated Jitter, Fibonacci, Response Content inspection, Total Timeout enforcement, and more.
- **Deep Laravel Integration**: Extends Laravel's HTTP Client with retry-focused macros (like `robustRetry`, `withCircuitBreaker`) and enhances Pipelines to support per-stage retry configuration, all while leveraging Laravel's configuration and event systems.
- **Smart Exception Handling**: Automatically detects and handles retryable exceptions with a flexible, extensible system.
- **Rich Observability**: Detailed context tracking and event system for monitoring and debugging retry sequences.
- **Promise-like API**: Clean, chainable interface for handling retry results without nested try/catch blocks.

### Key Features

- Multiple built-in retry strategies for different scenarios
- Simple strategy alias system for easier configuration (e.g., 'exponential-backoff', 'circuit-breaker')
- Seamless integration with Laravel's HTTP Client through dedicated macros
- Per-pipe retry configuration in Laravel pipelines
- Automatic exception handler discovery
- Comprehensive retry context and event system
- Promise-like result handling
- Configurable through standard Laravel configuration

### Real-World Problem Solving

Laravel Retry excels at solving common but tricky scenarios:

- APIs that return success status codes (e.g., 200 OK) but signal errors in the response body
- Operations requiring hard deadlines across multiple retry attempts
- Complex multi-step workflows needing different retry strategies per step
- Integration of sophisticated retry logic into HTTP calls without boilerplate
- Debugging and monitoring of complex retry sequences
- Easy extension for handling custom or third-party exceptions

Whether you're building a robust API client, managing complex workflows, or just want to make your application more resilient, Laravel Retry provides the tools and flexibility you need.

---

## Table of Contents

- [Installation](#installation)
  - [Requirements](#requirements)
  - [Quick Start](#quick-start)
  - [Configuration](#configuration)
- [Basic Usage](#basic-usage)
  - [Simple Retry Operation](#simple-retry-operation)
  - [The RetryResult Object](#the-retryresult-object)
  - [Configuring Retry Operations](#configuring-retry-operations)
  - [Understanding Delay Configuration](#understanding-delay-configuration)
  - [HTTP Client Integration](#http-client-integration)
  - [Pipeline Integration](#pipeline-integration)
- [Advanced Configuration](#advanced-configuration)
  - [Retry Strategies](#retry-strategies)
  - [Combining Strategies](#combining-strategies)
  - [Exception Handling](#exception-handling)
  - [Events & Monitoring](#events--monitoring)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

---

## Installation

### Requirements

- PHP 8.1 or higher
- Laravel 10.0, 11.0, or 12.0

### Quick Start

1. Install the package via Composer:

```bash
composer require gregpriday/laravel-retry
```

2. The package uses Laravel's auto-discovery, so the service provider and facade will be automatically registered. If you have disabled auto-discovery, manually add the following to your `config/app.php`:

```php
'providers' => [
    // ...
    GregPriday\LaravelRetry\RetryServiceProvider::class,
],

'aliases' => [
    // ...
    'Retry' => GregPriday\LaravelRetry\Facades\Retry::class,
],
```

### Configuration

1. Publish the configuration file:

```bash
php artisan vendor:publish --tag="retry-config"
```

This will create a `config/retry.php` file in your application's configuration directory, where you can customize retry settings.

### Configuration Summary

After publishing, you can customize the package's behavior in `config/retry.php`. Here are the key options:

* **`max_retries`** (Env: `RETRY_MAX_ATTEMPTS`): Maximum number of times an operation will be retried after the initial attempt fails (Default: `3`).
* **`timeout`** (Env: `RETRY_TIMEOUT`): Maximum execution time per attempt in seconds (Default: `120`).
* **`total_timeout`** (Env: `RETRY_TOTAL_TIMEOUT`): Maximum total time allowed for the entire operation, including all retries and delays (Default: `300`).
* **`default`** (Env: `RETRY_STRATEGY`): The kebab-case alias of the default retry strategy (Default: `exponential-backoff`).
  * Settings for each strategy are defined in the `strategies` section of the config file.

Available built-in strategy aliases:
  * `exponential-backoff`: Increases delay exponentially with each retry (Default)
  * `linear-backoff`: Increases delay by a fixed amount with each retry
  * `fixed-delay`: Uses the same delay for all retries
  * `fibonacci-backoff`: Increases delay according to the Fibonacci sequence
  * `decorrelated-jitter`: Uses AWS-style decorrelated jitter algorithm
  * `circuit-breaker`: Stops retrying after a threshold of failures
  * `rate-limit`: Controls retry frequency with Laravel's Rate Limiter
  * `total-timeout`: Enforces a maximum total time across all retry attempts
  * `guzzle-response`: Intelligently handles HTTP retries based on response headers
  * `response-content`: Inspects HTTP response bodies for error conditions
  * `custom-options`: Allows for flexible, customized retry behavior
  * `callback-retry`: Enables completely custom retry logic via callbacks

* **`strategies`**: Defines the default constructor options for each strategy when invoked via its alias (used when a strategy is specified as the `default` or as an inner strategy).
  * Dedicated sections for `circuit_breaker` and `response_content` provide more detailed configuration options for those specific strategies.
* **`dispatch_events`** (Env: `RETRY_DISPATCH_EVENTS`): Enables/disables Laravel events during the retry lifecycle for monitoring (Default: `true`).
* **`handler_paths`**: Directories containing custom `RetryableExceptionHandler` classes for automatic discovery.

For full configuration details, refer to the published `config/retry.php` file.

2. (Optional) Publish exception handlers:

```bash
php artisan vendor:publish --tag="retry-handlers"
```

This will copy the built-in exception handlers to your application's `app/Exceptions/Retry/Handlers` directory, allowing you to customize them or use them as templates for your own handlers.

### Get Started in 2 Minutes

Here's a minimal example to get started with Laravel Retry:

```php
use GregPriday\LaravelRetry\Facades\Retry;
use Illuminate\Support\Facades\Http;

// Simple retry with default settings
$data = Retry::run(function () {
    $response = Http::get('https://api.example.com/data');
    $response->throw(); // Will automatically retry on network errors & 5xx responses
    return $response->json();
})->value();

// With HTTP Client macro (even simpler)
$response = Http::robustRetry(3)  // Max 3 attempts total (1 initial + 2 retries)
    ->get('https://api.example.com/resource');

// If no strategy is explicitly provided, robustRetry uses GuzzleResponseStrategy as the base.
// When options are provided without a strategy, the GuzzleResponseStrategy is wrapped with
// CustomOptionsStrategy to apply those settings.
```

That's it! Laravel Retry will automatically handle common HTTP exceptions and retry with exponential backoff.

---

## Basic Usage

### Simple Retry Operation

The most basic way to use Laravel Retry is through the `Retry` facade:

```php
use GregPriday\LaravelRetry\Facades\Retry;
use Illuminate\Support\Facades\Http;

$result = Retry::run(function () {
        $response = Http::get('https://api.example.com/data');
        $response->throw();
        return $response->json();
    })
    ->then(function ($data) {
        return ['status' => 'success', 'payload' => $data];
    })
    ->catch(function (Throwable $e) {
        return ['status' => 'failed', 'error' => $e->getMessage()];
    });
```

You can customize retry behavior using a fluent interface:

```php
$result = Retry::maxRetries(5)    // Override default max retries
    ->timeout(10)                 // Override default timeout per attempt (seconds)
    ->withStrategy(new ExponentialBackoffStrategy(baseDelay: 0.5)) // Configure strategy with custom base delay
    ->run(function () {
        // Your operation here
    })
    ->value();                    // Get result directly or throws the final exception
```

#### The RetryResult Object

The `Retry::run()` method returns a `RetryResult` object that provides a promise-like interface for handling the operation's outcome. This avoids nested try/catch blocks and makes your code more readable.

Available methods on the `RetryResult` object:

| Method | Description |
|--------|-------------|
| `then(Closure $callback)` | Executes the callback if the operation succeeds, passing the operation's result as its parameter. Returns a new `RetryResult` with the callback's return value. |
| `catch(Closure $callback)` | Executes the callback if the operation fails, passing the exception as its parameter. Returns a new `RetryResult` with the callback's return value. |
| `finally(Closure $callback)` | Executes the callback regardless of whether the operation succeeds or fails. The callback receives no parameters. Returns the original `RetryResult`. |
| `value()` | Returns the operation's result directly. If the operation failed, throws the last exception that was caught. |
| `throw()` | Same as `value()` but with a more explicit name when you expect an exception may be thrown. |
| `throwFirst()` | Returns the result directly, but if the operation failed, throws the *first* exception that was caught instead of the last one. |

Example with the `finally` method:

```php
use GregPriday\LaravelRetry\Facades\Retry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

$lockKey = 'api-operation-lock';

$result = Retry::maxRetries(3)
    ->run(function () {
        // Set a lock to prevent concurrent operations
        Cache::put($lockKey, true, 60);
        
        // Operation that might fail
        $response = Http::get('https://api.example.com/data');
        $response->throw();
        return $response->json();
    })
    ->then(function ($data) {
        return ['status' => 'success', 'data' => $data];
    })
    ->catch(function (Throwable $e) {
        return ['status' => 'failed', 'error' => $e->getMessage()];
    })
    ->finally(function () use ($lockKey) {
        // This always executes regardless of success or failure
        // Perfect for cleanup operations
        Cache::forget($lockKey);
        Log::info('Operation completed, lock released');
    })
    ->value();
```

Example with comprehensive error handling:

```php
$processedData = Retry::maxRetries(3)
    ->run(function () {
        return DB::transaction(function () {
            // Complex database operation that might fail
            return processSensitiveData();
        });
    })
    ->then(function ($data) {
        // Process successful result
        Log::info('Data processed successfully', ['record_count' => count($data)]);
        return $data;
    })
    ->catch(function (QueryException $e) {
        // Handle database-specific errors
        Log::error('Database error during processing', ['error' => $e->getMessage()]);
        return ['error' => 'Database operation failed', 'retry_allowed' => true];
    })
    ->catch(function (Throwable $e) {
        // Handle any other exceptions
        Log::critical('Unexpected error during processing', ['error' => $e->getMessage()]);
        return ['error' => 'Processing failed', 'retry_allowed' => false];
    })
    ->finally(function () {
        // Clean up resources, always executed
        Cache::forget('processing_lock');
    })
    ->value(); // Get the final result (or throw the exception)
```

### Configuring Retry Operations

Laravel Retry provides several configuration options through fluent methods that allow you to customize how retry operations behave. These methods override the global configuration defined in `config/retry.php` for a specific operation:

#### Available Configuration Methods

| Fluent Method | Description | Config Key |
|---------------|-------------|------------|
| `maxRetries(int $retries)` | Sets maximum number of retry attempts after the initial try. | `max_retries` |
| `timeout(int $seconds)` | Sets maximum execution time per attempt in seconds. | `timeout` |
| `withStrategy(RetryStrategy $strategy)` | Specifies a custom retry strategy to use. | N/A |
| `retryIf(Closure $condition)` | Custom callback to determine if a retry should occur. | N/A |
| `retryUnless(Closure $condition)` | Custom callback to determine when a retry should NOT occur. | N/A |
| `withProgress(Closure $callback)` | Register callback for progress reporting during retries. | N/A |
| `withEventCallbacks(array $callbacks)` | Register callbacks for retry lifecycle events. | N/A |
| `withMetadata(array $metadata)` | Add custom data to the retry context. | N/A |

#### Understanding Delay Configuration

The delay between retry attempts is primarily controlled by the retry strategy used. The most important parameter across many strategies is `baseDelay`, which serves as the foundation for delay calculations:

- **What is `baseDelay`?** A floating-point value (in seconds) that defines the starting point for calculating delays between retry attempts.
- **Default values:** Default values for `baseDelay` (and other strategy options) are configured **per strategy alias** in `config/retry.php` within the `strategies` array. For example, the default `baseDelay` for `exponential-backoff` might be `0.1`, while for `fixed-delay` it might be `1.0`. These defaults are used when a strategy is invoked via its alias (e.g., when set as the `default` strategy in the config, or used as an `inner_strategy` for wrappers like Circuit Breaker).

How `baseDelay` is interpreted depends on the strategy:

- For `ExponentialBackoffStrategy`, it's the starting value that gets multiplied exponentially with each attempt (e.g., with multiplier 2.0: 0.1s, 0.2s, 0.4s, 0.8s...)
- For `FixedDelayStrategy`, it's the consistent delay used between each retry (e.g., always waits `baseDelay` seconds)
- For `LinearBackoffStrategy`, it's the starting point before increments are added (e.g., with increment 0.5: 0.5s, 1.0s, 1.5s...)
- For wrapper strategies (like `CircuitBreakerStrategy`, `RateLimitStrategy`), the `baseDelay` concept usually applies to their *inner* strategy. The configuration for these wrappers often involves specifying the alias of the inner strategy (see `config/retry.php` examples).

You can configure `baseDelay` (and other strategy options) in several ways, listed by precedence (later options override earlier ones):

1. **Globally per Strategy Alias:** Define the default constructor parameters, including `baseDelay`, for each strategy alias in `config/retry.php` under the `strategies` key (e.g., `config('retry.strategies.exponential-backoff.baseDelay')`).
2. **When Instantiating a Strategy Directly:** Pass `baseDelay` as a named argument to the strategy's constructor (e.g., `new ExponentialBackoffStrategy(baseDelay: 0.5)`).
3. **In HTTP Client Macros:** Use the `base_delay` option within the options array passed to macros like `robustRetry` or `withRetryStrategy` (e.g., `Http::robustRetry(3, null, ['base_delay' => 0.75])`).
4. **In Pipeline Stages:** Provide a custom `RetryStrategy` instance within a pipeline stage class, configured with its specific `baseDelay` in its constructor.

Example with different strategies (direct instantiation):

```php
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;

// With ExponentialBackoffStrategy - Overrides config default for this instance
// Base delay: 1.5s, then ~3s, then ~6s with jitter
Retry::withStrategy(new ExponentialBackoffStrategy(baseDelay: 1.5))
    ->run($operation);

// With FixedDelayStrategy - Overrides config default for this instance
// Every retry will wait 0.5s (plus jitter if enabled)
Retry::withStrategy(new FixedDelayStrategy(baseDelay: 0.5))
    ->run($operation);

// With LinearBackoffStrategy (increment: 1.5) - Overrides config default for this instance
// First retry after 1s, then 2.5s, then 4s
Retry::withStrategy(new LinearBackoffStrategy(baseDelay: 1.0, increment: 1.5))
    ->run($operation);
```

Most strategies in the library also support additional configuration parameters like `maxDelay`, `multiplier`, `increment`, `withJitter`, etc., that can be configured similarly (in `config/retry.php` under the specific strategy alias or via constructor parameters) to further customize the delay behavior. Refer to the `config/retry.php` file and the individual strategy classes for available options.

### HTTP Client Integration

Laravel Retry extends Laravel's HTTP Client with custom macros like `robustRetry`, `withCircuitBreaker`, and `withRateLimitHandling`. These macros are automatically registered when the package is installed, allowing you to use sophisticated retry patterns directly in your HTTP requests:

```php
use Illuminate\Support\Facades\Http;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

// Simple robust retry with default settings
$response = Http::robustRetry(3)  // Max 3 attempts total (1 initial + 2 retries)
    ->get('https://api.example.com/resource');

// Using a custom retry strategy
$strategy = new ExponentialBackoffStrategy(
    multiplier: 1.5,
    maxDelay: 60,
    withJitter: true
);

$response = Http::withRetryStrategy($strategy, [
        'max_attempts' => 5,
        'base_delay' => 2.5,  // Configures the baseDelay parameter of the underlying strategy
        'timeout' => 15,
    ])
    ->post('https://api.example.com/submit', ['foo' => 'bar']);

// Custom retry conditions
$response = Http::retryWhen(
    function ($attempt, $maxAttempts, $exception, $options) {
        return $attempt < $maxAttempts &&
               $exception instanceof RequestException &&
               $exception->response->status() === 503;
    },
    [
        'max_attempts' => 4,
        'timeout' => 10,
        'base_delay' => 0.75,  // Configures the baseDelay parameter of the underlying strategy
    ]
)->get('https://api.example.com/data');
```

> **Note**: The `max_attempts` parameter in HTTP client macros specifies the *total* number of attempts (initial + retries), whereas `Retry::maxRetries()` specifies the number of *additional* retries after the first attempt.

#### Additional HTTP Client Macros

Laravel Retry provides other specialized macros for common patterns:

##### Circuit Breaker

Use the Circuit Breaker pattern to prevent overwhelming failing services:

```php
use Illuminate\Support\Facades\Http;

// Apply circuit breaker with default parameters
// Uses GuzzleResponseStrategy as the default inner strategy
$response = Http::withCircuitBreaker()
    ->get('https://api.example.com/endpoint');

// Apply circuit breaker with custom parameters
// The base_delay configures the underlying inner strategy (GuzzleResponseStrategy)
$response = Http::withCircuitBreaker(
    failureThreshold: 5,    // Open after 5 failures
    resetTimeout: 60,       // Try again after 60 seconds
    [
        'max_attempts' => 3,
        'base_delay' => 1.0, // Configures the baseDelay of the inner strategy
        'timeout' => 5,
    ]
)->post('https://api.example.com/data', ['key' => 'value']);
```

##### Rate Limiting

Control the rate of requests to avoid overwhelming services:

```php
use Illuminate\Support\Facades\Http;

// Apply rate limiting with default parameters
$response = Http::withRateLimitHandling()
    ->get('https://api.example.com/endpoint');
    
// Apply rate limiting with custom parameters
$response = Http::withRateLimitHandling(
    maxAttempts: 100,      // Max 100 requests
    timeWindow: 60,        // Per minute
    storageKey: 'api-rate-limit',
    [
        'max_attempts' => 3,
        'base_delay' => 1.0,
    ]
)->get('https://api.example.com/data');
```

These macros can be combined with other Laravel HTTP client features:

```php
use Illuminate\Support\Facades\Http;

// Combining features
$response = Http::withToken('api-token')
    ->withCircuitBreaker(5, 60)
    ->withHeaders(['Custom-Header' => 'Value'])
    ->timeout(5)
    ->get('https://api.example.com/protected-endpoint');
```

### Pipeline Integration

For complex workflows where multiple steps need retry capabilities, use the `RetryablePipeline`:

```php
use GregPriday\LaravelRetry\Facades\RetryablePipeline;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;

// Define pipeline stages with custom retry configurations
class ValidateDataStage
{
    // Uses pipeline default settings
    public function handle($data, $next)
    {
        // Validation logic
        return $next($data);
    }
}

class ProcessDataStage
{
    public int $retryCount = 4;  // Override pipeline default retries
    public RetryStrategy $retryStrategy;  // Custom retry strategy for this stage

    public function __construct()
    {
        // Configure baseDelay in the strategy constructor
        $this->retryStrategy = new ExponentialBackoffStrategy(
            baseDelay: 2.0,
            multiplier: 1.5
        );
    }

    public function handle($data, $next)
    {
        // Process data here
        return $next($data);
    }
}

class SaveResultsStage
{
    public int $retryCount = 1;  // Only 1 retry for saving
    public int $timeout = 10;    // 10-second timeout per attempt
    
    public function handle($data, $next)
    {
        // Save results logic
        return $next($data);
    }
}

$result = RetryablePipeline::maxRetries(2)
    ->withStrategy(new FixedDelayStrategy(baseDelay: 0.5)) // Default strategy for all stages
    ->send(['initial' => 'data'])
    ->through([
        new ValidateDataStage(),     // Uses pipeline defaults (2 retries, FixedDelay)
        new ProcessDataStage(),      // Uses its own settings (4 retries, ExponentialBackoff)
        new SaveResultsStage(),      // Uses its own settings (1 retry, 10s timeout)
    ])
    ->then(function ($processedData) {
        return $processedData;
    });
```

Each stage in the pipeline can have its own retry configuration by defining public properties:
- `retryCount`: Maximum number of retries
- `retryStrategy`: Custom retry strategy (configured with its own baseDelay)
- `timeout`: Maximum time per attempt
- `additionalPatterns`: Additional exception patterns to retry on
- `additionalExceptions`: Additional exception types to retry on 

---

## Advanced Configuration

### Retry Strategies

Laravel Retry comes with a comprehensive set of retry strategies to handle different scenarios. Each strategy implements the `RetryStrategy` interface and can be used with the `Retry` facade, HTTP client, or pipeline integration.

#### Strategy Overview

| Strategy | Alias | Primary Use Case |
|----------|-------|------------------|
| **ExponentialBackoffStrategy** | `exponential-backoff` | Handles general temporary failures by exponentially increasing the delay between retries. |
| **LinearBackoffStrategy** | `linear-backoff` | Provides a predictable retry delay that increases by a fixed amount with each attempt. |
| **FibonacciBackoffStrategy** | `fibonacci-backoff` | Offers a balanced retry delay growth based on the Fibonacci sequence, suitable for various scenarios. |
| **FixedDelayStrategy** | `fixed-delay` | Applies a consistent, fixed delay between every retry attempt, ideal for predictable recovery times. |
| **DecorrelatedJitterStrategy** | `decorrelated-jitter` | Prevents retry collisions ("thundering herd") in high-traffic scenarios using AWS-style decorrelated jitter. |
| **GuzzleResponseStrategy** | `guzzle-response` | Intelligently retries HTTP requests based on standard response headers like `Retry-After` or `X-RateLimit-Reset`. |
| **ResponseContentStrategy** | `response-content` | Triggers retries by inspecting response content (like JSON error codes or text patterns) even when the HTTP status is successful. |
| **CircuitBreakerStrategy** | `circuit-breaker` | Prevents overwhelming a failing service by temporarily halting requests after repeated failures (Circuit Breaker pattern). |
| **RateLimitStrategy** | `rate-limit` | Controls retry frequency to respect API rate limits or manage load on internal services using Laravel's Rate Limiter. |
| **TotalTimeoutStrategy** | `total-timeout` | Ensures the entire retry operation (including delays) completes within a specific total time limit. |
| **CustomOptionsStrategy** | `custom-options` | Allows customizing an existing strategy's behavior with specific options and callbacks for one-off adjustments without extending base classes. Perfect for specific, fine-grained control over retry logic using closures. |
| **CallbackRetryStrategy** | `callback-retry` | Enables completely custom retry logic by defining both the delay calculation and the retry decision logic via callbacks. |

```php
use GregPriday\LaravelRetry\Facades\Retry;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

Retry::withStrategy(new ExponentialBackoffStrategy())
    ->run(function () {
        // Your operation here
    });
```

Available strategies:

#### 1. ExponentialBackoffStrategy (Alias: `exponential-backoff`)
Increases delay exponentially with each attempt. Best for general-purpose retries and temporary networking or service issues where increasing wait times helps recovery.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new ExponentialBackoffStrategy(
    baseDelay: 1.0,       // Initial value used for delay calculation (default: 1.0)
    multiplier: 2.0,      // Delay multiplier
    maxDelay: 60,         // Maximum delay in seconds
    withJitter: true,     // Add randomness to prevent thundering herd
    jitterPercent: 0.2    // Percentage of jitter (0.2 means ±20%)
);

// Using the factory with options
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('exponential-backoff', [
    'baseDelay' => 1.0,
    'multiplier' => 2.0,
    'maxDelay' => 60,
    'withJitter' => true,
    'jitterPercent' => 0.2
]);
```

#### 2. LinearBackoffStrategy (Alias: `linear-backoff`)
Increases delay by a fixed amount with each attempt. Useful when a more predictable increase in wait time is desired compared to exponential backoff.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;

$strategy = new LinearBackoffStrategy(
    baseDelay: 1.0,    // Base delay in seconds (default: 1.0)
    increment: 5,      // Add 5 seconds each retry
    maxDelay: 30       // Cap at 30 seconds
);

// Using the factory with options
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('linear-backoff', [
    'baseDelay' => 1.0,
    'increment' => 5,
    'maxDelay' => 30
]);
```

#### 3. FibonacciBackoffStrategy (Alias: `fibonacci-backoff`)
Increases delay according to the Fibonacci sequence. Good balance between aggressive and conservative retries, growing slower than exponential but faster than linear initially.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\FibonacciBackoffStrategy;

$strategy = new FibonacciBackoffStrategy(
    baseDelay: 1.0,      // Base delay in seconds (default: 1.0)
    maxDelay: 60,        // Maximum delay in seconds
    withJitter: true,    // Add randomness to prevent thundering herd
    jitterPercent: 0.2   // Percentage of jitter (0.2 means ±20%)
);

// Using the factory with options
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('fibonacci-backoff', [
    'baseDelay' => 1.0,
    'maxDelay' => 60,
    'withJitter' => true,
    'jitterPercent' => 0.2
]);
```

#### 4. FixedDelayStrategy (Alias: `fixed-delay`)
Uses the same delay for every retry attempt. Ideal when the expected recovery time is consistent or when predictable delays are needed.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;

$strategy = new FixedDelayStrategy(
    baseDelay: 1.0,       // Fixed delay between all retries (default: 1.0)
    withJitter: true,     // Add randomness
    jitterPercent: 0.2    // ±20% jitter
);

// Using the factory with options
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('fixed-delay', [
    'baseDelay' => 1.0,
    'withJitter' => true,
    'jitterPercent' => 0.2
]);
```

#### 5. DecorrelatedJitterStrategy (Alias: `decorrelated-jitter`)
Implements AWS-style jitter for better distribution of retries. Excellent for high-traffic scenarios to prevent the "thundering herd" problem where many clients retry simultaneously.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;

$strategy = new DecorrelatedJitterStrategy(
    baseDelay: 1.0,     // Base delay in seconds (default: 1.0)
    maxDelay: 60,       // Maximum delay
    minFactor: 1.0,     // Minimum delay multiplier
    maxFactor: 3.0      // Maximum delay multiplier
);

// Using the factory with options
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('decorrelated-jitter', [
    'baseDelay' => 1.0,
    'maxDelay' => 60,
    'minFactor' => 1.0,
    'maxFactor' => 3.0
]);
```

#### 6. GuzzleResponseStrategy (Alias: `guzzle-response`)
Intelligent HTTP retry strategy that respects response headers. Perfect for APIs that provide retry guidance through headers like `Retry-After` or `X-RateLimit-Reset`.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\GuzzleResponseStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new GuzzleResponseStrategy(
    baseDelay: 1.0,                                   // Base delay in seconds (default: 1.0)
    innerStrategy: new ExponentialBackoffStrategy(baseDelay: 0.5),  // Optional custom inner strategy
    maxDelay: 60
);

// Using the factory with options
// Inner strategy defaults to application default if not specified
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('guzzle-response', [
    'baseDelay' => 1.0, // Configures the default inner strategy's base delay
    'maxDelay' => 60
    // 'innerStrategy' => new CustomInnerStrategy() // Can also provide an instance
]);
```

#### 7. ResponseContentStrategy (Alias: `response-content`)
Inspects response bodies for error conditions, even on successful status codes. Use for APIs that return success HTTP codes (200 OK) but signal errors via JSON response body.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\ResponseContentStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new ResponseContentStrategy(
    innerStrategy: new ExponentialBackoffStrategy(baseDelay: 0.5),  // Inner strategy controls delay
    retryableContentPatterns: ['/server busy/i', '/try again/i'], // Regex patterns in body
    retryableErrorCodes: ['TRY_AGAIN'],
    errorCodePaths: ['error.status_code']
);

// Using the factory with options
// Options are typically loaded from config('retry.response_content')
// Inner strategy defaults to the application's default retry strategy
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('response-content');

// Or provide specific options (less common, usually configured globally)
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('response-content', [
    'retryableContentPatterns' => ['/custom pattern/'],
    'retryableErrorCodes' => ['MY_CODE'],
    'errorCodePaths' => ['data.error_status'],
    // 'innerStrategy' can also be provided here if needed
]);
```

#### 8. CircuitBreakerStrategy (Alias: `circuit-breaker`)
Implements the Circuit Breaker pattern to prevent overwhelming failing services. After a threshold of failures, it "opens" and temporarily stops attempts, allowing the service to recover.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new CircuitBreakerStrategy(
    innerStrategy: new ExponentialBackoffStrategy(baseDelay: 2.0),  // Inner strategy controls delay
    failureThreshold: 3,    // Open after 3 failures
    resetTimeout: 120,      // Stay open for 2 minutes
    cacheKey: 'my_service_circuit', // Optional unique key for this circuit
    cacheTtl: 1440, // Cache TTL in minutes (default: 1 day)
    failOpenOnCacheError: false // Default behavior on cache errors
);

// Using the factory with options
// Creates a circuit breaker using default or named service settings from config
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('circuit-breaker');

// Or with specific options
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('circuit-breaker', [
    'failureThreshold' => 5,
    'resetTimeout' => 60,
    // Inner strategy defaults to application default if not specified
]);
```

#### 9. RateLimitStrategy (Alias: `rate-limit`)
Uses Laravel's Rate Limiter to control retry attempts. Ideal for respecting API rate limits or managing load on internal services.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;

$strategy = new RateLimitStrategy(
    innerStrategy: new FixedDelayStrategy(baseDelay: 0.5),  // Inner strategy controls delay
    maxAttempts: 50,    // Max attempts allowed
    timeWindow: 60,     // Within this time window (seconds)
    storageKey: 'api-rate-limiter'  // Unique key for the rate limiter
);

// Using the factory with options
// Inner strategy defaults to application default if not specified
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('rate-limit', [
    'maxAttempts' => 100,
    'timeWindow' => 60,
    'storageKey' => 'my_api_limit'
    // 'innerStrategy' => new CustomInnerStrategy() // Can also provide an instance
]);
```

#### 10. TotalTimeoutStrategy (Alias: `total-timeout`)
Enforces a maximum total duration for the entire retry operation. Use when an operation must complete within a strict time budget, regardless of individual attempt results.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\TotalTimeoutStrategy;
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;

$strategy = new TotalTimeoutStrategy(
    innerStrategy: new LinearBackoffStrategy(baseDelay: 0.5),  // Inner strategy controls delay
    totalTimeout: 30.0    // Complete within 30 seconds (float for precision)
);

// Using the factory with options
// totalTimeout loaded from config('retry.total_timeout')
// Inner strategy defaults to the application's default retry strategy
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('total-timeout');

// Or with specific options
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('total-timeout', [
    'totalTimeout' => 45.5, // Override total timeout
    // 'innerStrategy' => new CustomInnerStrategy() // Can also provide an instance
]);
```

#### 11. CustomOptionsStrategy (Alias: `custom-options`)
Allows customizing an existing strategy's behavior with specific options and callbacks for one-off adjustments without extending base classes. Perfect for specific, fine-grained control over retry logic using closures.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\CustomOptionsStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new CustomOptionsStrategy(
    baseDelay: 1.0, // Base delay reference for callbacks
    innerStrategy: new ExponentialBackoffStrategy(baseDelay: 0.5), // Base strategy to potentially fall back to
    options: ['custom_flag' => true, 'user_id' => 123] // Custom data for callbacks
);

// Override the retry decision logic
$strategy->withShouldRetryCallback(function ($attempt, $maxAttempts, $exception, $options) {
    // Only retry if custom_flag is true and within attempts
    return $options['custom_flag'] && $attempt < $maxAttempts;
});

// Override the delay calculation logic
$strategy->withDelayCallback(function ($attempt, $baseDelay, $options) {
    // Use baseDelay, but maybe double it if custom_flag is set
    return $baseDelay * ($options['custom_flag'] ? 2 : 1);
});

// Using the factory with options (callbacks set after instantiation)
$strategy = \GregPriday\LaravelRetry\Factories\StrategyFactory::make('custom-options', [
    'baseDelay' => 2.0,
    'options' => ['initial_option' => 'value']
    // Inner strategy defaults to application default
]);
// $strategy->withShouldRetryCallback(...)
// $strategy->withDelayCallback(...)

// Note: Direct instantiation is generally preferred for this strategy
// since callbacks are fundamental to its functionality
```

#### 12. CallbackRetryStrategy (Alias: `callback-retry`)
A fully callback-driven strategy where you define *both* the delay calculation and the retry decision logic via closures. Ideal for completely custom retry patterns without needing a base strategy.

```php
// Using the class directly
use GregPriday\LaravelRetry\Strategies\CallbackRetryStrategy;
use App\Exceptions\CustomTransientException; // Example custom exception

$strategy = new CallbackRetryStrategy(
    // Define how delay is calculated (receives attempt, baseDelay, maxAttempts, exception, options)
    delayCallback: fn($attempt, $baseDelay) => $baseDelay * ($attempt + 1), // e.g., 1s, 2s, 3s...

    // Define when to retry (optional, defaults to checking attempts)
    // Receives (attempt, maxAttempts, exception, options)
    shouldRetryCallback: fn($attempt, $maxAttempts, $exception) =>
        $attempt < $maxAttempts && $exception instanceof CustomTransientException,

    baseDelay: 1.0, // Reference delay value for delayCallback (default: 1.0)
    options: ['log_retries' => true] // Custom data passed to callbacks (default: [])
);

// Usage:
Retry::withStrategy($strategy)->maxRetries(3)->run(fn() => /* operation */);

// Note: Creating via factory alias is less common as callbacks must be provided in the constructor.
// You would typically instantiate this directly as shown above.
```

### Combining Strategies

Many strategies can be combined by wrapping one strategy inside another:

```php
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

// Create a strategy that implements circuit breaking, rate limiting, and exponential backoff
$exponentialStrategy = new ExponentialBackoffStrategy(
    baseDelay: 0.5,
    multiplier: 2.0
);

$rateStrategy = new RateLimitStrategy(
    innerStrategy: $exponentialStrategy,
    maxAttempts: 50,
    timeWindow: 60
);

$strategy = new CircuitBreakerStrategy(
    innerStrategy: $rateStrategy,
    failureThreshold: 3,
    resetTimeout: 120
); 
```

> **Note on Wrapper Strategies:** When using wrapper strategies (like CircuitBreaker, RateLimit, TotalTimeout) via aliases or HTTP macros, configuration options like `baseDelay` typically apply to the default inner strategy. When instantiating wrappers directly as shown above, configure the inner strategy explicitly with its own parameters.

### Exception Handling

Laravel Retry provides a sophisticated exception handling system that determines whether a failure should trigger a retry attempt. The system is extensible and configurable to match your specific needs.

#### Exception Handler Discovery

The package uses an automatic discovery mechanism to find and register exception handlers from:

1. Built-in handlers in the package
2. Custom handlers in your application's `app/Exceptions/Retry/Handlers` directory
3. Any additional directories specified in the `handler_paths` array in `config/retry.php`

Each handler determines which specific exceptions or error patterns should trigger a retry attempt.

#### Built-in Exception Handling

By default, the package includes handlers for common scenarios:

```php
use GregPriday\LaravelRetry\Facades\Retry;

// Using default exception handlers
$result = Retry::run(function () {
    // This will automatically retry on common HTTP client exceptions
    $response = Http::get('https://api.example.com/data');
    $response->throw();
    return $response->json();
});
```

The built-in handlers cover common cases like:

- HTTP connection errors (timeouts, DNS failures, etc.)
- Rate limiting responses (429 Too Many Requests)
- Server errors (500, 502, 503, 504)
- Database deadlocks and lock timeouts
- Temporary network issues

#### Custom Retry Conditions

You can define custom conditions for retrying:

```php
use GregPriday\LaravelRetry\Facades\Retry;

$result = Retry::retryIf(function (Throwable $e, int $attempt) {
        // Custom logic to determine if retry is needed
        return $e instanceof CustomException && $attempt < 5;
    })
    ->run(function () {
        // Your operation here
    });

// Or use retryUnless for inverse logic
$result = Retry::retryUnless(function (Throwable $e, int $attempt) {
        return $e instanceof PermanentFailureException;
    })
    ->run(function () {
        // Your operation here
    });
```

Example combining a custom handler with `retryIf`:

```php
use GregPriday\LaravelRetry\Facades\Retry;
use App\Exceptions\Retry\Handlers\CustomApiHandler;
use Illuminate\Support\Facades\Http;
use Throwable;

// Create a custom handler instance
$handler = new CustomApiHandler();

$result = Retry::retryIf(function (Throwable $e, int $attempt) use ($handler) {
        // First check our custom handler to see if exception is generally retryable
        $handlerAllowsRetry = $handler->isRetryable($e);
        
        // Then add additional specific conditions for this operation
        $isRateLimitError = $e instanceof RequestException && $e->response->status() === 429;
        
        // Only retry rate limit errors for a few attempts
        return $handlerAllowsRetry && (!$isRateLimitError || $attempt < 3);
    })
    ->run(function () {
        $response = Http::get('https://api.example.com/data');
        $response->throw();
        return $response->json();
    });
```

#### Creating Custom Exception Handlers

For more reusable exception handling, implement the `RetryableExceptionHandler` interface:

1. Create a new handler in `app/Exceptions/Retry/Handlers`:

```php
namespace App\Exceptions\Retry\Handlers;

use GregPriday\LaravelRetry\Contracts\RetryableExceptionHandler;
use GregPriday\LaravelRetry\Exceptions\Handlers\BaseHandler;

class CustomApiHandler extends BaseHandler implements RetryableExceptionHandler
{
    public function isApplicable(): bool
    {
        // Return true if this handler should be active
        return true;
    }

    public function getExceptions(): array
    {
        return [
            CustomApiException::class,
            AnotherCustomException::class,
        ];
    }

    public function getPatterns(): array
    {
        return [
            '/rate limit exceeded/i',
            '/server temporarily unavailable/i',
        ];
    }
}
```

2. The handler will be automatically discovered and registered by the `ExceptionHandlerManager`.

#### Adding Custom Handler Paths

If you want to organize your handlers in different locations, add the paths to the `handler_paths` array in `config/retry.php`:

```php
'handler_paths' => [
    app_path('Exceptions/Retry/Handlers'),
    app_path('Services/API/RetryHandlers'),
    // Add more paths here
],
```

#### Disabling Specific Handlers

You can temporarily disable specific handler types by extending the base handler and overriding the `isApplicable()` method:

```php
namespace App\Exceptions\Retry\Handlers;

use GregPriday\LaravelRetry\Exceptions\Handlers\DatabaseHandler;

class CustomDatabaseHandler extends DatabaseHandler 
{
    public function isApplicable(): bool
    {
        // Disable this handler in testing environment
        if (app()->environment('testing')) {
            return false;
        }
        
        return parent::isApplicable();
    }
}
```

### Events & Monitoring

Laravel Retry dispatches events at key points in the retry lifecycle, allowing you to monitor and respond to retry operations.

#### Available Events

Laravel Retry dispatches the following events:

1. `RetryingOperationEvent`: Dispatched before each retry attempt, contains information about the current attempt, the delay before the retry, and the exception that caused the retry.
2. `OperationSucceededEvent`: Dispatched when the operation succeeds, contains the final result, the number of attempts, and performance metrics.
3. `OperationFailedEvent`: Dispatched when all retries are exhausted and the operation ultimately fails, contains the final exception, exception history, and performance metrics.

Each event includes a `RetryContext` object containing detailed information about the retry operation.

#### The RetryContext Object

The `RetryContext` object provides comprehensive information about the retry operation:

| Property/Method | Description |
|-----------------|-------------|
| `getOperationId()` | Returns the unique identifier for this specific retry operation |
| `getMetadata()` | Returns any custom data added via `withMetadata()` |
| `getMetrics()` | Returns performance metrics including `total_duration`, `average_duration`, `total_delay`, `min_duration`, `max_duration`, and `total_elapsed_time` for monitoring and analytics. Details:
  * `total_duration`: Sum of execution time for each attempt (excluding delays)
  * `average_duration`: Average execution time per attempt
  * `total_delay`: Sum of time spent waiting between retry attempts
  * `min_duration`/`max_duration`: Minimum/Maximum execution time observed across attempts
  * `total_elapsed_time`: Total wall-clock time from start to completion |
| `getExceptionHistory()` | Returns an array of exceptions caught during previous attempts |
| `getMaxRetries()` | Returns the maximum number of retries configured for this operation |
| `getStartTime()` | Returns the timestamp when the retry operation started |
| `getTotalAttempts()` | Returns the total number of attempts made (including the initial attempt) |
| `getTotalDelay()` | Returns the total time spent waiting between retry attempts |
| `getSummary()` | Returns a summary array of retry operation statistics |

#### Practical Event Use Cases

Events provide powerful hooks into the retry lifecycle, enabling various monitoring and operational tasks:

- **Logging**: Track retry attempts, successes, and failures for audit trails and debugging
- **Alerting**: Send notifications to Slack or other platforms when operations consistently fail
- **Metrics**: Submit metrics to monitoring systems like Prometheus/Datadog to visualize retry patterns
- **Resource Management**: Release locks or clean up resources when operations complete 
- **Dynamic Configuration**: Adjust retry parameters based on external conditions or previous attempt results

#### Using Events

Register listeners in your `EventServiceProvider`:

```php
use GregPriday\LaravelRetry\Events\RetryingOperationEvent;
use GregPriday\LaravelRetry\Events\OperationSucceededEvent;
use GregPriday\LaravelRetry\Events\OperationFailedEvent;

protected $listen = [
    RetryingOperationEvent::class => [
        LogRetryAttemptListener::class,
    ],
    OperationSucceededEvent::class => [
        LogSuccessListener::class,
    ],
    OperationFailedEvent::class => [
        LogFailureListener::class,
        NotifyAdminListener::class,
    ],
];
```

Or use inline event callbacks:

```php
use GregPriday\LaravelRetry\Facades\Retry;
use Illuminate\Support\Facades\Log;

Retry::withEventCallbacks([
    'onRetrying' => function ($event) {
        Log::info('Retrying operation', [
            'operation_id' => $event->context->getOperationId(),
            'attempt' => $event->attemptNumber,
            'delay' => $event->delay,
            'error' => $event->exception->getMessage(),
            'remaining_attempts' => $event->context->getMaxRetries() - $event->attemptNumber + 1,
        ]);
    },
    'onSuccess' => function ($event) {
        Log::info('Operation succeeded', [
            'operation_id' => $event->context->getOperationId(),
            'total_attempts' => $event->context->getTotalAttempts(),
            'total_time' => $event->context->getMetrics()['total_duration'],
            'result' => $event->result,
        ]);
    },
    'onFailure' => function ($event) {
        Log::error('Retry operation failed', [
            'operation_id' => $event->context->getOperationId(),
            'total_attempts' => $event->context->getTotalAttempts(),
            'total_duration' => $event->context->getMetrics()['total_duration'],
            'average_duration' => $event->context->getMetrics()['average_duration'],
            'total_delay' => $event->context->getTotalDelay(),
            'exception' => get_class($event->exception),
            'message' => $event->exception->getMessage(),
            'exception_history' => collect($event->context->getExceptionHistory())
                ->map(fn ($e) => ['class' => get_class($e), 'message' => $e->getMessage()])
                ->toArray(),
            'metadata' => $event->context->getMetadata(),
        ]);
    }
])
->run(function () {
    // Your operation here
});
```

#### Example Event Listener

Here's an example of a listener for the `OperationFailedEvent`:

```php
use GregPriday\LaravelRetry\Events\OperationFailedEvent;
use Illuminate\Support\Facades\Log;

class LogFailureListener
{
    public function handle(OperationFailedEvent $event)
    {
        $context = $event->context;

        Log::error('Retry operation failed', [
            'operation_id' => $context->getOperationId(),
            'total_attempts' => $context->getTotalAttempts(),
            'total_duration' => $context->getMetrics()['total_duration'],
            'average_duration' => $context->getMetrics()['average_duration'],
            'total_delay' => $context->getTotalDelay(),
            'exception' => get_class($event->exception),
            'message' => $event->exception->getMessage(),
            'exception_history' => collect($context->getExceptionHistory())
                ->map(fn ($e) => ['class' => get_class($e), 'message' => $e->getMessage()])
                ->toArray(),
            'metadata' => $context->getMetadata(),
        ]);
    }
}
```

---

## Troubleshooting

Here are solutions to common issues you might encounter:

### Operation Isn't Retrying When Expected

- **Check Exception Handlers**: Ensure your exception type is covered by active handlers. Publish handlers with `php artisan vendor:publish --tag="retry-handlers"` to inspect built-in logic.
- **Check Conditions**: If using `retryIf` or `retryUnless`, verify your closure returns the correct boolean value.
- **Verify Max Retries**: Ensure `maxRetries` in your config (or `.maxRetries()` call) is greater than 0.
- **Check Base Delay**: Verify the `baseDelay` configuration (in `config/retry.php` or strategy constructors) if the delay between retries seems incorrect.

### Custom Exception Handler Not Being Used

- **Path**: Make sure your handler is in `app/Exceptions/Retry/Handlers` or a path listed in `config('retry.handler_paths')`.
- **Class Name**: Ensure it ends with `Handler` (e.g., `CustomApiHandler.php`).
- **Interface**: Verify it implements `GregPriday\LaravelRetry\Contracts\RetryableExceptionHandler`.
- **Applicability**: Make sure `isApplicable()` returns `true` when expected.

### HTTP Client Macros Not Working

- **Service Provider**: Ensure `GregPriday\LaravelRetry\Http\HttpClientServiceProvider` is registered.
- **Parameters**: Double-check the parameters. For example, `robustRetry` takes `maxAttempts` (including first attempt), while the `Retry` facade uses `maxRetries` (additional attempts after the first).

### Pipeline Stage Retries Using Incorrect Settings

- **Property Names**: Verify you're using the correct property names in your stage class: `retryCount`, `retryStrategy`, `timeout`, etc.
- **Initialization**: Make sure custom strategies in stages are properly initialized in constructors.

### Events Not Firing

- **Config**: Ensure `dispatch_events` is set to `true` in `config/retry.php`.
- **Listeners**: Verify your event listeners are correctly registered in your `EventServiceProvider`.

If problems persist, check your Laravel logs (`storage/logs/laravel.log`) and consider enabling the `withProgress()` callback for more verbose output during retries.

---

## Contributing

We welcome contributions to Laravel Retry! Here's how you can help:

### Reporting Issues

If you discover a bug or have a feature request:

1. Search the [GitHub issues](https://github.com/gregpriday/laravel-retry/issues) to see if it has already been reported.
2. If not, [create a new issue](https://github.com/gregpriday/laravel-retry/issues/new) with as much detail as possible.

### Pull Requests

1. Fork the repository
2. Create a new branch for your feature or bug fix
3. Write tests for your changes
4. Ensure all tests pass by running `vendor/bin/phpunit`
5. Ensure code style compliance by running `vendor/bin/pint`
6. Submit a pull request with a clear description of your changes

### Development Setup

```bash
# Clone your fork
git clone git@github.com:YOUR_USERNAME/laravel-retry.git

# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Format code
vendor/bin/pint
```

### Code Style

This package follows the Laravel coding style. We use Laravel Pint for code formatting:

```bash
# Check code style
vendor/bin/pint --test

# Fix code style
vendor/bin/pint
```

---

## License

Laravel Retry is open-sourced software licensed under the [MIT license](LICENSE.md).