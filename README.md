# Laravel Retry

A robust and flexible retry mechanism for Laravel applications that handles transient failures gracefully. This package provides an elegant way to retry operations that may fail temporarily, such as HTTP requests, database queries, or any other potentially unstable operations.

## Features

- Exponential backoff retry strategy
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