# Laravel Retry

A robust and flexible retry mechanism for Laravel applications that handles transient failures gracefully. This package provides an elegant way to retry operations that may fail temporarily, such as HTTP requests, database queries, or any other potentially unstable operations.

## Features

- Multiple retry strategies for different use cases
- Configurable retry attempts, delays, and timeouts
- Progress tracking and logging
- Built-in support for common HTTP/Guzzle exceptions
- Extensible exception handling system
- Fluent interface for easy configuration
- Laravel Facade support
- Fully tested

## Installation

You can install the package via Composer:

```bash
composer require gregpriday/laravel-retry
```

After installation, publish the configuration file:

```bash
php artisan vendor:publish --tag="retry-config"
```

## Configuration

The package can be configured via environment variables or the published config file (`config/retry.php`):

```php
return [
    'max_retries' => env('RETRY_MAX_ATTEMPTS', 3),    // Maximum number of retry attempts
    'delay' => env('RETRY_DELAY', 5),                 // Base delay between retries (seconds)
    'timeout' => env('RETRY_TIMEOUT', 120),           // Maximum execution time per attempt
];
```

## Basic Usage

### Using the Facade

The simplest way to use the retry mechanism is through the Facade:

```php
use GregPriday\LaravelRetry\Facades\Retry;

$result = Retry::run(function () {
    return Http::get('https://api.example.com/data');
});
```

### Using Dependency Injection

For better testability, you can inject the Retry service:

```php
use GregPriday\LaravelRetry\Retry;

class ExampleService
{
    public function __construct(
        protected Retry $retry
    ) {}

    public function getData()
    {
        return $this->retry->run(function () {
            return DB::table('users')->get();
        });
    }
}
```

## Retry Strategies

The package includes several retry strategies to handle different scenarios and requirements. You can select the most appropriate strategy for your use case.

### Available Strategies

#### 1. Exponential Backoff Strategy (Default)
Increases delay exponentially between retries, helping prevent overwhelming systems under stress.

```php
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new ExponentialBackoffStrategy(
    multiplier: 2.0,  // Each delay will be 2x longer
    maxDelay: 30,     // Maximum delay in seconds
    withJitter: true  // Add randomness to prevent thundering herd
);

Retry::withStrategy($strategy)->run(fn () => doSomething());
```

#### 2. Linear Backoff Strategy
Increases delay linearly between retries. Useful when you want predictable, steady increases in delay.

```php
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;

$strategy = new LinearBackoffStrategy(
    increment: 5,    // Add 5 seconds each retry
    maxDelay: 30    // Maximum delay in seconds
);

Retry::withStrategy($strategy)->run(fn () => doSomething());
```

#### 3. Fixed Delay Strategy
Uses the same delay between all retries. Suitable for simple retry scenarios.

```php
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;

$strategy = new FixedDelayStrategy(
    withJitter: true,      // Add randomness to prevent thundering herd
    jitterPercent: 0.2    // ±20% jitter
);

Retry::withStrategy($strategy)->run(fn () => doSomething());
```

#### 4. Decorrelated Jitter Strategy
Implements AWS's "Exponential Backoff and Jitter" algorithm for distributed systems.

```php
use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;

$strategy = new DecorrelatedJitterStrategy(
    maxDelay: 30,      // Maximum delay in seconds
    minFactor: 1.0,    // Minimum multiplier for base delay
    maxFactor: 3.0     // Maximum multiplier for base delay
);

Retry::withStrategy($strategy)->run(fn () => doSomething());
```

#### 5. Rate Limit Strategy
Implements rate limiting across multiple retry attempts. Useful for APIs with rate limits.

```php
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;

$strategy = new RateLimitStrategy(
    innerStrategy: new FixedDelayStrategy(),  // Base strategy for delays
    maxAttempts: 100,                        // Maximum attempts per time window
    timeWindow: 60                           // Time window in seconds
);

Retry::withStrategy($strategy)->run(fn () => doSomething());
```

#### 6. Circuit Breaker Strategy
Implements the Circuit Breaker pattern to prevent cascading failures in distributed systems.

```php
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;

$strategy = new CircuitBreakerStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),  // Base strategy for delays
    failureThreshold: 5,                             // Failures before opening circuit
    resetTimeout: 60                                 // Seconds before attempting reset
);

Retry::withStrategy($strategy)->run(fn () => doSomething());
```

### Strategy Selection Guide

- **Exponential Backoff**: Default choice for most scenarios. Good balance between retry attempts and system protection.
- **Linear Backoff**: When you need predictable delay increases and exponential might grow too quickly.
- **Fixed Delay**: Simple scenarios where complexity isn't needed. Add jitter for distributed systems.
- **Decorrelated Jitter**: Large-scale distributed systems where preventing thundering herd is critical.
- **Rate Limit**: When working with rate-limited APIs or need to control request frequency.
- **Circuit Breaker**: Protecting downstream services, preventing cascading failures in distributed systems.

### Combining Strategies

Strategies can be combined for more complex scenarios. For example, using Circuit Breaker with Rate Limit:

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

Retry::withStrategy($circuitBreaker)->run(fn () => doSomething());
```

## Advanced Usage

### Customizing Retry Behavior

Chain methods to customize retry behavior for specific operations:

```php
use GregPriday\LaravelRetry\Facades\Retry;

$result = Retry::maxRetries(5)                // Set maximum attempts
    ->retryDelay(2)                          // Set base delay (seconds)
    ->timeout(60)                            // Set timeout per attempt
    ->withProgress(function ($message) {     // Add progress tracking
        logger()->info($message);
        event(new RetryAttempted($message));
    })
    ->run(function () {
        return Http::get('https://api.example.com/data');
    });
```

### Custom Retry Conditions

Add custom error patterns or exception types that should trigger retries:

```php
Retry::run(
    fn () => doSomething(),
    ['/service unavailable/i'],          // Custom error patterns
    [\CustomException::class]            // Custom exception classes
);
```

### Exception Handling

The package includes built-in handlers for common exceptions:

- Network timeouts
- Connection refused errors
- Server errors
- SSL/TLS errors
- Rate limiting
- Temporary unavailability

You can add custom exception handlers by extending the `BaseHandler` class:

```php
use GregPriday\LaravelRetry\Exceptions\Handlers\BaseHandler;

class CustomHandler extends BaseHandler
{
    protected function getHandlerPatterns(): array
    {
        return [
            '/custom error pattern/i'
        ];
    }

    protected function getHandlerExceptions(): array
    {
        return [
            CustomException::class
        ];
    }

    public function isApplicable(): bool
    {
        return true;
    }
}
```

## Testing

Run the test suite:

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Greg Priday](https://github.com/gregpriday)
- [All Contributors](../../contributors)

## Support

For bugs and feature requests, please use the [issue tracker](../../issues).