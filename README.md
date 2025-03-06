# Laravel Retry

A robust and flexible retry mechanism for Laravel applications that provides a powerful way to handle transient failures. This package helps you handle temporary failures in HTTP requests, database queries, API calls, and any other potentially unstable operations.

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

- **Comprehensive Configuration**
    - Configurable retry attempts
    - Adjustable delays and timeouts
    - Progress tracking and logging
    - Custom retry conditions

## Installation

You can install the package via Composer:

```bash
composer require gregpriday/laravel-retry
```

After installation, you can publish the configuration file:

```bash
php artisan vendor:publish --tag="retry-config"
```

## Basic Usage

### Simple Retry Operation

```php
use GregPriday\LaravelRetry\Facades\Retry;

$result = Retry::run(function () {
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

### Using Dependency Injection

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
            ->value();
    }
}
```

### Configuring Retry Behavior

```php
Retry::make()
    ->maxRetries(5)                    // Maximum retry attempts
    ->retryDelay(2)                    // Base delay in seconds
    ->timeout(30)                      // Timeout per attempt
    ->withProgress(function ($message) {
        Log::info($message);           // Track progress
    })
    ->run(function () {
        return DB::table('users')->get();
    });
```

### Custom Retry Conditions

```php
Retry::make()
    ->retryIf(function (Throwable $e, array $context) {
        // Retry on rate limit errors if we have attempts remaining
        return $e instanceof RateLimitException && 
               $context['remaining_attempts'] > 0;
    })
    ->run(function () {
        return $api->fetch();
    });

// Or use retryUnless for inverse conditions
Retry::make()
    ->retryUnless(function (Throwable $e, array $context) {
        // Don't retry if we've seen too many of the same error
        return $context['attempt'] >= 2;
    })
    ->run(function () {
        return $api->fetch();
    });
```

## Retry Strategies

The package includes several retry strategies to handle different scenarios:

### Exponential Backoff (Default)

Increases delay exponentially between retries:

```php
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$strategy = new ExponentialBackoffStrategy(
    multiplier: 2.0,    // Each delay will be 2x longer
    maxDelay: 30,       // Maximum delay in seconds
    withJitter: true    // Add randomness to prevent thundering herd
);

Retry::withStrategy($strategy)->run(fn() => doSomething());
```

### Linear Backoff

Increases delay linearly between retries:

```php
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;

$strategy = new LinearBackoffStrategy(
    increment: 5,     // Add 5 seconds each retry
    maxDelay: 30      // Maximum delay in seconds
);
```

### Fixed Delay

Uses the same delay between all retries:

```php
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;

$strategy = new FixedDelayStrategy(
    withJitter: true,       // Add randomness
    jitterPercent: 0.2     // ±20% jitter
);
```

### Decorrelated Jitter

Implements AWS's "Exponential Backoff and Jitter" algorithm:

```php
use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;

$strategy = new DecorrelatedJitterStrategy(
    maxDelay: 30,       // Maximum delay in seconds
    minFactor: 1.0,     // Minimum multiplier for base delay
    maxFactor: 3.0      // Maximum multiplier for base delay
);
```

### Rate Limit Strategy

Implements rate limiting across multiple retry attempts:

```php
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;

$strategy = new RateLimitStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    maxAttempts: 100,    // Maximum attempts per time window
    timeWindow: 60       // Time window in seconds
);
```

### Circuit Breaker Strategy

Implements the Circuit Breaker pattern:

```php
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;

$strategy = new CircuitBreakerStrategy(
    innerStrategy: new ExponentialBackoffStrategy(),
    failureThreshold: 5,    // Failures before opening circuit
    resetTimeout: 60        // Seconds before attempting reset
);
```

### Combining Strategies

Strategies can be combined for complex scenarios:

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

## Result Handling

The package provides a promise-like interface for handling results:

```php
$result = Retry::run(fn() => riskyOperation())
    ->then(function($value) {
        // Handle success
        return processValue($value);
    })
    ->catch(function(Throwable $e) {
        // Handle failure
        Log::error('Operation failed', ['error' => $e]);
        return fallbackValue();
    })
    ->finally(function() {
        // Always runs
        cleanup();
    });

// Access the final value (throws on error)
$value = $result->value();

// Or handle success/failure states explicitly
if ($result->succeeded()) {
    $value = $result->getResult();
} else {
    $error = $result->getError();
}

// Access retry history
$history = $result->getExceptionHistory();
```

# Laravel Retry - Advanced Usage

## Observability & Instrumentation

### Event System

The package dispatches Laravel events at key points in the retry lifecycle, allowing you to monitor and log retry behavior:

```php
use GregPriday\LaravelRetry\Events\RetryingOperationEvent;
use GregPriday\LaravelRetry\Events\OperationSucceededEvent;
use GregPriday\LaravelRetry\Events\OperationFailedEvent;

// In your EventServiceProvider or elsewhere:
protected $listen = [
    RetryingOperationEvent::class => [
        MyRetryingOperationListener::class,
    ],
    OperationSucceededEvent::class => [
        MyOperationSucceededListener::class,
    ],
    OperationFailedEvent::class => [
        MyOperationFailedListener::class,
        NotifyAdminAboutFailedOperation::class,
    ],
];
```

### Available Events

- **RetryingOperationEvent**
  - Dispatched before each retry attempt
  - Payload: `attempt`, `maxRetries`, `delay`, `exception`, `timestamp`

- **OperationSucceededEvent**
  - Dispatched when an operation succeeds
  - Payload: `attempt`, `result`, `totalTime`, `timestamp`

- **OperationFailedEvent**
  - Dispatched when all retry attempts are exhausted
  - Payload: `attempt`, `error`, `exceptionHistory`, `timestamp`

### Event Callbacks

For simpler use cases, you can use the callback approach instead of Laravel's event system:

```php
Retry::make()
    ->withEventCallbacks([
        'onRetry' => function (RetryingOperationEvent $event) {
            Log::info('Retrying operation', [
                'attempt' => $event->attempt,
                'exception' => $event->exception->getMessage(),
                'delay' => $event->delay,
            ]);
        },
        'onSuccess' => function (OperationSucceededEvent $event) {
            Log::info('Operation succeeded', [
                'attempts' => $event->attempt,
                'totalTime' => $event->totalTime,
            ]);
        },
        'onFailure' => function (OperationFailedEvent $event) {
            Log::error('Operation failed after multiple attempts', [
                'attempts' => $event->attempt,
                'error' => $event->error->getMessage(),
            ]);
            
            // Send notification or alert
            Notification::route('slack', config('slack.webhook'))
                ->notify(new OperationFailureNotification($event));
        },
    ])
    ->run(fn() => riskyOperation());
```

### Configuration

Event dispatching can be enabled or disabled via configuration:

```php
// config/retry.php
return [
    // ... other config
    
    'dispatch_events' => env('RETRY_DISPATCH_EVENTS', true),
];
```

### Integrating with Monitoring Systems

You can use the event system to integrate with popular monitoring systems:

```php
// Example integration with a monitoring system
Event::listen(RetryingOperationEvent::class, function ($event) {
    app('monitoring')->incrementCounter('retry_attempts', [
        'operation' => 'api_call',
    ]);
});

Event::listen(OperationFailedEvent::class, function ($event) {
    app('monitoring')->incrementCounter('retry_failures', [
        'operation' => 'api_call',
    ]);
    
    if ($event->attempt >= 3) {
        app('monitoring')->triggerAlert('High retry count detected');
    }
});
```

## Exception Handling System

### Built-in Exception Handlers

The package includes built-in handlers for common exceptions:
- Network timeouts
- Connection errors
- Rate limiting
- Server errors
- SSL/TLS issues
- Temporary unavailability

### Custom Exception Handlers

Create custom handlers by extending the `BaseHandler` class:

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
        return true;
    }
}
```

Register custom handlers:

```php
// In a service provider
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;

public function boot(ExceptionHandlerManager $manager)
{
    $manager->registerHandler(new CustomDatabaseHandler());
}
```

### Exception History

Track and analyze retry attempts:

```php
$result = Retry::run(fn() => riskyOperation());

foreach ($result->getExceptionHistory() as $entry) {
    Log::info('Retry attempt details', [
        'attempt' => $entry['attempt'],
        'exception' => $entry['exception']->getMessage(),
        'timestamp' => $entry['timestamp'],
        'was_retryable' => $entry['was_retryable']
    ]);
}
```

## Testing & Debugging

### Testing Retry Logic

The package is designed for easy testing:

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

### Debugging Tools

Monitor retry operations:

```php
Retry::make()
    ->withProgress(function ($message) {
        Log::channel('retry')->info($message);
        
        // Or send to monitoring service
        Monitoring::recordRetryAttempt([
            'message' => $message,
            'timestamp' => now()
        ]);
    })
    ->run(fn() => operation());
```

## Advanced Configuration

### Global Configuration

Customize default behavior in `config/retry.php`:

```php
return [
    'max_retries' => env('RETRY_MAX_ATTEMPTS', 3),
    'delay' => env('RETRY_DELAY', 5),
    'timeout' => env('RETRY_TIMEOUT', 120),
    'handler_paths' => [
        app_path('Exceptions/Retry/Handlers')
    ],
];
```

### Runtime Configuration

Apply configuration per operation:

```php
Retry::make()
    ->maxRetries(5)
    ->retryDelay(2)
    ->timeout(30)
    ->withStrategy(new CustomStrategy())
    ->withProgress($callback)
    ->retryIf($condition)
    ->run(fn() => operation());
```

### Service Container Bindings

Customize the service registration:

```php
use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;

$this->app->bind(Retry::class, function ($app) {
    return new Retry(
        maxRetries: 5,
        retryDelay: 1,
        timeout: 30,
        strategy: new ExponentialBackoffStrategy(
            multiplier: 2.0,
            maxDelay: 30,
            withJitter: true
        )
    );
});
```

## Performance Considerations

### Memory Usage

The package maintains an exception history for each retry operation. For long-running processes or high-frequency retries, consider:

```php
// Clear exception history between runs if needed
$retry = Retry::make();

foreach ($items as $item) {
    $result = $retry->run(fn() => processItem($item));
    // History is automatically reset on next run
}
```

### Timeout Handling

Set appropriate timeouts to prevent long-running operations:

```php
Retry::make()
    ->timeout(5)  // Maximum time per attempt
    ->run(function() {
        return Http::timeout(3)->get('https://api.example.com');
    });
```

## Common Patterns & Best Practices

### Idempotency

Ensure operations are safe to retry:

```php
Retry::run(function() {
    return DB::transaction(function() {
        // Use idempotency keys or check existence
        if (!Payment::whereReference($ref)->exists()) {
            return Payment::create([...]);
        }
    });
});
```

### Rate Limiting

Handle API rate limits:

```php
$strategy = new RateLimitStrategy(
    new ExponentialBackoffStrategy(),
    maxAttempts: 100,
    timeWindow: 60,
    storageKey: 'api-client'  // Separate limits per client
);

Retry::withStrategy($strategy)
    ->retryIf(function($e) {
        return $e instanceof RateLimitException;
    })
    ->run(fn() => apiCall());
```

### Circuit Breaking

Protect downstream services:

```php
$circuitBreaker = new CircuitBreakerStrategy(
    new ExponentialBackoffStrategy(),
    failureThreshold: 5,
    resetTimeout: 60
);

$retry = Retry::make()->withStrategy($circuitBreaker);

while (true) {
    try {
        $result = $retry->run(fn() => serviceCall());
        // Process result...
    } catch (Exception $e) {
        // Circuit is open, wait before retrying
        sleep($circuitBreaker->getResetTimeout());
    }
}
```

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

### Development Setup

1. Clone the repository
2. Install dependencies:
```bash
composer install
```

### Running Tests

```bash
composer test
```

Style fixing:
```bash
composer format
```

### Submitting Changes

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Support

- For bugs and features, use the [GitHub issue tracker](../../issues)
- Feel free to [email me](mailto:greg@siteorigin.com).

## Credits

- [Greg Priday](https://github.com/gregpriday)
- [All Contributors](../../contributors)
