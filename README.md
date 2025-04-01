# Laravel Retry

A powerful, flexible, and deeply integrated retry system for Laravel applications that goes beyond simple retry loops. This package provides sophisticated retry strategies, deep Laravel integration, and comprehensive observability to make your applications more resilient to transient failures.

## Introduction

In modern web applications, dealing with external services, APIs, and databases is commonplace. However, these interactions can fail due to temporary issues like network glitches, rate limits, or service unavailability. Laravel Retry provides a robust solution to handle these transient failures elegantly and efficiently.

What sets Laravel Retry apart:

- **Comprehensive Retry Strategies**: Beyond basic exponential backoff, offering sophisticated strategies like Circuit Breaker, Rate Limiting, AWS-style Decorrelated Jitter, and more.
- **Deep Laravel Integration**: Seamlessly integrates with Laravel's HTTP Client and Pipeline systems through fluent APIs and macros.
- **Smart Exception Handling**: Automatically detects and handles retryable exceptions with a flexible, extensible system.
- **Rich Observability**: Detailed context tracking and event system for monitoring and debugging retry sequences.
- **Promise-like API**: Clean, chainable interface for handling retry results without nested try/catch blocks.

### Key Features

- Multiple built-in retry strategies for different scenarios
- Seamless integration with Laravel's HTTP Client through dedicated macros
- Per-pipe retry configuration in Laravel pipelines
- Automatic exception handler discovery
- Comprehensive retry context and event system
- Promise-like result handling
- Configurable through standard Laravel configuration

### Real-World Problem Solving

Laravel Retry excels at solving common but tricky scenarios:

- APIs returning success status codes (200 OK) but containing error responses
- Operations requiring hard deadlines across multiple retry attempts
- Complex multi-step workflows needing different retry strategies per step
- Integration of sophisticated retry logic into HTTP calls without boilerplate
- Debugging and monitoring of complex retry sequences
- Easy extension for handling custom or third-party exceptions

Whether you're building a robust API client, managing complex workflows, or just want to make your application more resilient, Laravel Retry provides the tools and flexibility you need.

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

2. This will create `config/retry.php` with the following options:

```php
return [
    // Maximum number of retry attempts
    'max_retries' => env('RETRY_MAX_ATTEMPTS', 3),

    // Base delay between retries in seconds
    'delay' => env('RETRY_DELAY', 5),

    // Maximum time per attempt in seconds
    'timeout' => env('RETRY_TIMEOUT', 120),

    // Maximum time for entire operation including all retries
    'total_timeout' => env('RETRY_TOTAL_TIMEOUT', 300),

    // Enable/disable event dispatching
    'dispatch_events' => env('RETRY_DISPATCH_EVENTS', true),

    // Additional paths for custom exception handlers
    'handler_paths' => [
        app_path('Exceptions/Retry/Handlers'),
    ],
];
```

3. (Optional) Publish exception handlers:

```bash
php artisan vendor:publish --tag="retry-handlers"
```

This will copy the built-in exception handlers to your application's `app/Exceptions/Retry/Handlers` directory, allowing you to customize them or use them as templates for your own handlers.

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
    ->retryDelay(2)               // Override default base delay (seconds)
    ->timeout(10)                 // Override default timeout per attempt (seconds)
    ->run(function () {
        // Your operation here
    })
    ->value();                    // Get result directly or throws the final exception
```

### HTTP Client Integration

Laravel Retry provides seamless integration with Laravel's HTTP client through convenient macros:

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
        'base_delay' => 2,
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
        'base_delay' => 1,
    ]
)->get('https://api.example.com/data');
```

### Pipeline Integration

For complex workflows where multiple steps need retry capabilities, use the `RetryablePipeline`:

```php
use GregPriday\LaravelRetry\Facades\RetryablePipeline;

class ProcessDataStage
{
    public int $retryCount = 4;  // Override pipeline default retries
    public int $retryDelay = 1;  // Override pipeline default delay

    public function handle($data, $next)
    {
        // Process data here
        return $next($data);
    }
}

$result = RetryablePipeline::maxRetries(2)
    ->retryDelay(1)
    ->send(['initial' => 'data'])
    ->through([
        new ValidateDataStage(),
        new ProcessDataStage(),    // Uses its own retry settings
        new SaveResultsStage(),
    ])
    ->then(function ($processedData) {
        return $processedData;
    });
```

Each stage in the pipeline can have its own retry configuration by defining public properties:
- `retryCount`: Maximum number of retries
- `retryDelay`: Delay between retries in seconds
- `retryStrategy`: Custom retry strategy
- `timeout`: Maximum time per attempt
- `additionalPatterns`: Additional exception patterns to retry on
- `additionalExceptions`: Additional exception types to retry on 

## Advanced Configuration

### Retry Strategies

Laravel Retry comes with a comprehensive set of retry strategies to handle different scenarios. Each strategy implements the `RetryStrategy` interface and can be used with the `Retry` facade, HTTP client, or pipeline integration.

```php
use GregPriday\LaravelRetry\Facades\Retry;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

Retry::withStrategy(new ExponentialBackoffStrategy())
    ->run(function () {
        // Your operation here
    });
```

Available strategies:

#### 1. ExponentialBackoffStrategy (Default)
Increases delay exponentially with each attempt. Best for general-purpose retries.

```php
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new ExponentialBackoffStrategy(
    multiplier: 2.0,    // Delay multiplier
    maxDelay: 60,       // Maximum delay in seconds
    withJitter: true    // Add randomness to prevent thundering herd
);
```

#### 2. LinearBackoffStrategy
Increases delay by a fixed amount with each attempt.

```php
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;

$strategy = new LinearBackoffStrategy(
    increment: 5,     // Add 5 seconds each retry
    maxDelay: 30      // Cap at 30 seconds
);
```

#### 3. FixedDelayStrategy
Uses the same delay for every retry attempt.

```php
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;

$strategy = new FixedDelayStrategy(
    withJitter: true,      // Add randomness
    jitterPercent: 0.2     // ±20% jitter
);
```

#### 4. DecorrelatedJitterStrategy
Implements AWS-style jitter for better distribution of retries.

```php
use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;

$strategy = new DecorrelatedJitterStrategy(
    maxDelay: 60,      // Maximum delay
    minFactor: 1.0,    // Minimum delay multiplier
    maxFactor: 3.0     // Maximum delay multiplier
);
```

#### 5. GuzzleResponseStrategy
Intelligent HTTP retry strategy that respects response headers.

```php
use GregPriday\LaravelRetry\Strategies\GuzzleResponseStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new GuzzleResponseStrategy(
    fallbackStrategy: new ExponentialBackoffStrategy(),
    maxDelay: 60
);
```

#### 6. ResponseContentStrategy
Inspects response bodies for error conditions, even on successful status codes.

```php
use GregPriday\LaravelRetry\Strategies\ResponseContentStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new ResponseContentStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    retryableContentPatterns: ['/server busy/i'],
    retryableErrorCodes: ['TRY_AGAIN'],
    errorCodePaths: ['error.status_code']
);
```

#### 7. CircuitBreakerStrategy
Implements the Circuit Breaker pattern to prevent overwhelming failing services.

```php
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new CircuitBreakerStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    failureThreshold: 3,    // Open after 3 failures
    resetTimeout: 120       // Stay open for 2 minutes
);
```

#### 8. RateLimitStrategy
Uses Laravel's Rate Limiter to control retry attempts.

```php
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;

$strategy = new RateLimitStrategy(
    innerStrategy: new FixedDelayStrategy(),
    maxAttempts: 50,
    timeWindow: 60,                  // 50 attempts per minute
    storageKey: 'api-rate-limiter'
);
```

#### 9. TotalTimeoutStrategy
Enforces a maximum total duration for the entire retry operation.

```php
use GregPriday\LaravelRetry\Strategies\TotalTimeoutStrategy;
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;

$strategy = new TotalTimeoutStrategy(
    innerStrategy: new LinearBackoffStrategy(),
    totalTimeout: 30    // Complete within 30 seconds
);
```

#### 10. CustomOptionsStrategy
Create custom retry behavior without extending the base classes.

```php
use GregPriday\LaravelRetry\Strategies\CustomOptionsStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new CustomOptionsStrategy(
    new ExponentialBackoffStrategy(),
    ['custom_flag' => true]
);

$strategy->withShouldRetryCallback(function ($attempt, $maxAttempts, $exception, $options) {
    return $options['custom_flag'] && $attempt < $maxAttempts;
});

$strategy->withDelayCallback(function ($attempt, $baseDelay, $options) {
    return $baseDelay * ($options['custom_flag'] ? 2 : 1);
});
```

### Combining Strategies

Many strategies can be combined by wrapping one strategy inside another:

```php
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

// Create a strategy that implements circuit breaking, rate limiting, and exponential backoff
$strategy = new CircuitBreakerStrategy(
    innerStrategy: new RateLimitStrategy(
        innerStrategy: new ExponentialBackoffStrategy(),
        maxAttempts: 50,
        timeWindow: 60
    ),
    failureThreshold: 3,
    resetTimeout: 120
); 
```

### Exception Handling

Laravel Retry provides a sophisticated exception handling system that determines whether a failure should trigger a retry attempt. The system is extensible and configurable to match your specific needs.

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

#### Custom Exception Handlers

Create your own exception handlers by implementing the `RetryableExceptionHandler` interface:

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

2. The handler will be automatically discovered and registered.

### Events & Monitoring

Laravel Retry dispatches events at key points in the retry lifecycle, allowing you to monitor and respond to retry operations.

#### Available Events

1. `RetryingOperationEvent`: Dispatched before each retry attempt
2. `OperationSucceededEvent`: Dispatched when the operation succeeds
3. `OperationFailedEvent`: Dispatched when all retries are exhausted

Each event includes a `RetryContext` object containing detailed information about the retry operation.

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

Retry::withEventCallbacks(
    onRetrying: function ($event) {
        Log::info('Retrying operation', [
            'attempt' => $event->attempt,
            'delay' => $event->delay,
            'error' => $event->exception->getMessage(),
        ]);
    },
    onSuccess: function ($event) {
        Log::info('Operation succeeded', [
            'attempt' => $event->attempt,
            'result' => $event->result,
            'totalTime' => $event->totalTime,
        ]);
    },
    onFailure: function ($event) {
        Log::error('Operation failed permanently', [
            'attempt' => $event->attempt,
            'error' => $event->error->getMessage(),
            'history' => $event->exceptionHistory,
        ]);
    }
)->run(function () {
    // Your operation here
});
```

#### Retry Context

The `RetryContext` object provides detailed information about the retry operation:

```php
use GregPriday\LaravelRetry\Events\OperationFailedEvent;

public function handle(OperationFailedEvent $event)
{
    $context = $event->context;

    Log::error('Retry operation failed', [
        'operation_id' => $context->operationId,
        'total_attempts' => $context->getTotalAttempts(),
        'total_duration' => $context->metrics['total_duration'],
        'average_duration' => $context->metrics['average_duration'],
        'total_delay' => $context->getTotalDelay(),
        'exception_history' => $context->exceptionHistory,
    ]);
}
```

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

## License

Laravel Retry is open-sourced software licensed under the [MIT license](LICENSE.md).