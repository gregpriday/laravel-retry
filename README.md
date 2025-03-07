# Laravel Retry

A robust and flexible retry mechanism for Laravel applications to handle transient failures in HTTP requests, database queries, API calls, and any other potentially unstable operations. The package provides a host of powerful features to automate and customize retry logic in your application.

## Features

- **Multiple Retry Strategies**
    - Exponential Backoff (with optional jitter)
    - Linear Backoff
    - Fixed Delay
    - Decorrelated Jitter (AWS-style)
    - Rate Limiting
    - Circuit Breaker pattern
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

### 5. Rate Limit Strategy

Limit the total number of retry attempts within a time window:

```php
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;

$strategy = new RateLimitStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    maxAttempts: 100, // Maximum attempts in timeWindow
    timeWindow: 60    // in seconds
);
```

### 6. Circuit Breaker Strategy

Use the Circuit Breaker pattern to stop retrying after repeated failures:

```php
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;

$strategy = new CircuitBreakerStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    failureThreshold: 5,  // Failures before opening circuit
    resetTimeout: 60      // Wait time (seconds) before half-open attempt
);
```

### Combining Strategies

You can nest or chain strategies for complex scenarios:

```php
$rateLimit = new RateLimitStrategy(
    new ExponentialBackoffStrategy(),
    maxAttempts: 100,
    timeWindow: 60
);

$circuitBreaker = new CircuitBreakerStrategy(
    $rateLimit,
    failureThreshold: 5,
    resetTimeout: 60
);

Retry::withStrategy($circuitBreaker)->run(fn() => doSomething());
```

---

## Result Handling

Every retry operation returns a `RetryResult`, which you can handle in a promise-like style:

```php
$result = Retry::run(fn() => riskyOperation())
    ->then(function ($value) {
        // Handle success
        return processValue($value);
    })
    ->catch(function (Throwable $e) {
        // Handle failure
        Log::error('Operation failed', ['error' => $e]);
        return fallbackValue();
    })
    ->finally(function () {
        // Always runs
        cleanup();
    });

// Retrieve the final value or throw an exception
$value = $result->value(); // throws on error
```

Alternatively, you can handle it more manually:

```php
if ($result->succeeded()) {
    // success path
    $value = $result->getResult();
} else {
    // failure path
    $error = $result->getError();
}
```

You can also inspect the entire **exception history** via `$result->getExceptionHistory()`.

---

# Advanced Usage

## Observability & Instrumentation

### Event System

To facilitate monitoring and analytics, the package fires Laravel events at key points:

- `RetryingOperationEvent` – before each retry attempt
- `OperationSucceededEvent` – after a successful operation
- `OperationFailedEvent` – after retries are exhausted

Register these events in your **EventServiceProvider**:

```php
protected $listen = [
    RetryingOperationEvent::class => [
        MyRetryingOperationListener::class,
    ],
    OperationSucceededEvent::class => [
        MyOperationSucceededListener::class,
    ],
    OperationFailedEvent::class => [
        MyOperationFailedListener::class,
    ],
];
```

You can also enable/disable event dispatching in `config/retry.php`:

```php
'dispatch_events' => env('RETRY_DISPATCH_EVENTS', true),
```

### Event Callbacks (Alternative to Listeners)

For smaller use cases, pass callbacks:

```php
Retry::make()
    ->withEventCallbacks([
        'onRetry' => function (RetryingOperationEvent $event) {
            // ...
        },
        'onSuccess' => function (OperationSucceededEvent $event) {
            // ...
        },
        'onFailure' => function (OperationFailedEvent $event) {
            // ...
        },
    ])
    ->run(fn() => riskyOperation());
```

### Monitoring System Integrations

You can hook into the events to push metrics to services like Datadog, Prometheus, etc.:

```php
Event::listen(RetryingOperationEvent::class, function ($event) {
    app('monitoring')->incrementCounter('retry_attempts');
});
```

---

## Exception Handling System

### Built-in Handlers

By default, `laravel-retry` includes handlers for common transient errors (e.g., Guzzle timeouts, server errors, SSL errors, etc.). The package auto-detects these if the relevant libraries are installed.

### Custom Handlers

You can extend `BaseHandler` to match specific patterns, exceptions, or frameworks:

```php
use GregPriday\LaravelRetry\Exceptions\Handlers\BaseHandler;

class CustomDatabaseHandler extends BaseHandler
{
    protected function getHandlerPatterns(): array
    {
        return [
            '/deadlock found/i',
            '/lock wait timeout/i',
            '/connection lost/i'
        ];
    }

    protected function getHandlerExceptions(): array
    {
        return [
            \PDOException::class,
            \Doctrine\DBAL\Exception\DeadlockException::class,
            \Illuminate\Database\QueryException::class
        ];
    }

    public function isApplicable(): bool
    {
        // Return true if the environment supports this handler
        return true;
    }
}
```

Then register it in your **AppServiceProvider** or a dedicated service provider:

```php
public function boot(ExceptionHandlerManager $manager)
{
    $manager->registerHandler(new CustomDatabaseHandler());
}
```

### Exception History

For debugging or analytics, the retry result keeps a log of every exception:

```php
$result = Retry::run(fn() => riskyOperation());

foreach ($result->getExceptionHistory() as $entry) {
    Log::info('Attempt details', [
        'attempt' => $entry['attempt'],
        'message' => $entry['exception']->getMessage(),
        'timestamp' => $entry['timestamp'],
        'was_retryable' => $entry['was_retryable'],
    ]);
}
```

---

## Testing & Debugging

Testing retry scenarios is straightforward. For example, in a PHPUnit test:

```php
use GregPriday\LaravelRetry\Tests\TestCase;

class MyServiceTest extends TestCase
{
    public function test_it_retries_failed_operations()
    {
        $counter = 0;

        $result = $this->retry
            ->maxRetries(3)
            ->run(function() use (&$counter) {
                $counter++;
                if ($counter < 3) {
                    throw new Exception('Temporary failure');
                }
                return 'success';
            });

        $this->assertTrue($result->succeeded());
        $this->assertEquals(3, $counter);
        $this->assertCount(2, $result->getExceptionHistory());
    }
}
```

Because `laravel-retry` is designed for easy configuration and standard Laravel testing practices, you can mock or fake timeouts as needed.

---

## RetryablePipeline

### Overview

`RetryablePipeline` extends Laravel’s `Pipeline` class, adding retry logic around each pipe. This is ideal for multi-step workflows where each step (pipe) can fail with transient issues. Instead of the entire pipeline failing at the first error, each pipe is retried according to your settings.

### 1. Basic Usage

```php
use GregPriday\LaravelRetry\Facades\RetryablePipeline;

$result = RetryablePipeline::send($data)
    ->through([
        FirstPipe::class,
        SecondPipe::class,
        ThirdPipe::class,
    ])
    ->then(function ($processedData) {
        return $processedData;
    });
```

### 2. Configuring Retry Behavior

```php
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Facades\RetryablePipeline;

$result = RetryablePipeline::send($data)
    ->maxRetries(5)
    ->retryDelay(2)
    ->timeout(30)
    ->withStrategy(new ExponentialBackoffStrategy())
    ->withProgress(function ($message) {
        Log::info("Pipeline progress: {$message}");
    })
    ->withAdditionalPatterns([
        '/connection lost/i',
        '/temporary failure/i'
    ])
    ->withAdditionalExceptions([
        \App\Exceptions\TransientException::class
    ])
    ->through([
        // define your pipes...
    ])
    ->then(function ($processedData) {
        return $processedData;
    });
```

- **`maxRetries()`, `retryDelay()`, `timeout()`**: Set pipeline-wide defaults.
- **`withStrategy()`**: Choose a global retry strategy.
- **`withProgress()`**: Log progress on failures.
- **`withAdditionalPatterns()` & `withAdditionalExceptions()`**: Expand which errors are retryable at the pipeline level.

### 3. Pipe-Specific Retry Settings

Individual pipes can override the pipeline defaults by defining public properties:

```php
class ApiRequestPipe
{
    public $retryCount = 10;
    public $retryDelay = 3;
    public $timeout = 60;

    public $additionalPatterns = [
        '/rate limit exceeded/i',
        '/too many requests/i'
    ];

    public $additionalExceptions = [
        \App\Exceptions\RateLimitException::class
    ];

    // Optional: Provide your own strategy
    public $retryStrategy;

    public function __construct()
    {
        $this->retryStrategy = new LinearBackoffStrategy(increment: 5);
    }

    public function handle($data, $next)
    {
        // Potentially failing operation
        $response = Http::get('https://api.example.com/data');
        
        if ($response->failed()) {
            throw new RuntimeException('API request failed: ' . $response->status());
        }
        
        $data['api_result'] = $response->json();
        return $next($data);
    }
}
```

### 4. Combining with Other Retry Features

`RetryablePipeline` works seamlessly with:

- **Event system** for pipeline retries.
- **Custom exception handlers**.
- **Any configured retry strategy**.
- **Progress callbacks**.

This allows you to build highly robust, multi-step processes that recover gracefully from transient errors in each step.

---

## Contributing & Support

- **Issues**: Please open issues on GitHub if you encounter bugs or need further help.
- **Pull Requests**: Contributions are welcome! Submit a PR for any improvements, bug fixes, or new features.

We hope **Laravel Retry** helps you build more resilient applications!

---

**Thank you for using Laravel Retry!** If you have any questions, comments, or suggestions, feel free to open an issue or contribute on GitHub.
