# Changelog

All notable changes to the Laravel Retry package will be documented in this file.

## [0.2.0] - 2025-04-01

### Added
- New retry strategies:
  - FibonacciBackoffStrategy for better backoff calculations with large numbers
  - CustomOptionsStrategy for configurable retry behavior
  - ResponseContentStrategy for content-based retry decisions
  - TotalTimeoutStrategy to limit total execution time
- Event system with dedicated event classes:
  - OperationFailedEvent
  - OperationSucceededEvent
  - RetryingOperationEvent
- RetryablePipeline for pipeline-based retry operations
- Laravel HTTP client integration via HttpClientServiceProvider
- RetryContext for improved context handling between retries
- Support for Laravel 12
- Additional test coverage for all new features

### Changed
- Improved CircuitBreakerStrategy implementation
- Enhanced GuzzleResponseStrategy and RateLimitStrategy
- Reorganized test structure with dedicated strategy test directory
- Updated documentation with badges and better examples

### Removed
- Dead letter queue functionality
- Captain Hook dependency

## [0.1.0] - 2024-11-23

### Added
- Base retry functionality with configurable attempts
- Exception handling with automatic retries
- Basic retry strategies:
  - CircuitBreakerStrategy
  - GuzzleResponseStrategy
  - RateLimitStrategy
- Configuration system via Laravel config
- Facade for easy access to retry functionality 