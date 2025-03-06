<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Exception;
use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;

class RetryNestedExceptionsTest extends TestCase
{
    public function test_retry_detects_retryable_nested_exception(): void
    {
        // Create a retry instance
        $retry = new Retry(maxRetries: 3, retryDelay: 0);

        $attempts = 0;

        // The operation should retry because the inner exception is retryable
        $result = $retry->run(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                // Create a retryable Guzzle exception
                $innerException = $this->createGuzzleException('Connection timed out');

                // Wrap it in a non-retryable exception
                throw new RuntimeException('Operation failed', 0, $innerException);
            }

            return 'success';
        })->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts, 'The operation should have been attempted 3 times');
        $this->assertEquals(2, $retry->getRetryableExceptionCount(), 'Should have 2 retryable exceptions');
    }

    public function test_retry_detects_retryable_nested_exception_with_pattern(): void
    {
        // Create a retry instance
        $retry = new Retry(maxRetries: 3, retryDelay: 0);

        $attempts = 0;

        // The operation should retry because the inner exception matches the pattern
        $result = $retry->run(
            function () use (&$attempts) {
                $attempts++;

                if ($attempts < 3) {
                    // Create a non-retryable exception with a message that would be retryable
                    $innerException = new Exception('Connection timed out');

                    // Wrap it in another non-retryable exception with a non-retryable message
                    throw new RuntimeException('Operation failed', 0, $innerException);
                }

                return 'success';
            },
            ['/Connection timed out/i']
        )->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts, 'The operation should have been attempted 3 times');
    }

    public function test_retry_detects_retryable_deeply_nested_exception(): void
    {
        // Create a retry instance
        $retry = new Retry(maxRetries: 3, retryDelay: 0);

        $attempts = 0;

        // The operation should retry because the inner exception is retryable
        $result = $retry->run(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                // Create a retryable Guzzle exception
                $innerMostException = $this->createGuzzleException('Connection timed out');

                // Wrap it in a non-retryable exception
                $innerException = new InvalidArgumentException('Invalid argument', 0, $innerMostException);

                // Wrap that in another non-retryable exception
                throw new RuntimeException('Operation failed', 0, $innerException);
            }

            return 'success';
        })->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts, 'The operation should have been attempted 3 times');
    }

    public function test_non_retryable_exception_with_retryable_message_in_previous(): void
    {
        // Create a retry instance
        $retry = new Retry(maxRetries: 3, retryDelay: 0);

        $attempts = 0;

        // The operation should retry because the exception message contains a retryable pattern
        $result = $retry->run(
            function () use (&$attempts) {
                $attempts++;

                if ($attempts < 3) {
                    // Create a retryable exception based on message
                    $innerException = new Exception('Some other error');

                    // The outer exception has the retryable message, not the inner one
                    throw new RuntimeException('Connection timed out', 0, $innerException);
                }

                return 'success';
            },
            ['/Connection timed out/i']
        )->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts, 'The operation should have been attempted 3 times');
    }

    public function test_custom_exception_classes_with_nested_exceptions(): void
    {
        // Create a custom exception class for testing
        $customException = new class('Custom exception') extends Exception {};

        // Create a retry instance with the custom exception type
        $retry = new Retry(maxRetries: 3, retryDelay: 0);

        $attempts = 0;

        // The operation should retry because we'll add the custom exception class to the retryable list
        $result = $retry->run(
            function () use (&$attempts, $customException) {
                $attempts++;

                if ($attempts < 3) {
                    // Create a non-retryable exception
                    $innerException = new RuntimeException('Some error');

                    // Wrap it in our custom exception
                    throw new $customException('Custom error wrapper', 0, $innerException);
                }

                return 'success';
            },
            [],
            [$customException::class]
        )->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts, 'The operation should have been attempted 3 times');
    }
}
