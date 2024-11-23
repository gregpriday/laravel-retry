<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use GregPriday\LaravelRetry\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;

class RetryTest extends TestCase
{
    public function test_successful_operation_executes_once(): void
    {
        $counter = 0;

        $result = $this->retry->run(function () use (&$counter) {
            $counter++;
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $counter);
    }

    public function test_operation_retries_on_retryable_exception(): void
    {
        $result = $this->retry->run($this->createFailingCallback(2));

        $this->assertEquals('success', $result);
    }

    public function test_operation_fails_after_max_retries(): void
    {
        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection timed out');

        $this->retry->run($this->createFailingCallback(5));
    }

    public function test_non_retryable_exception_throws_immediately(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Non-retryable error');

        $counter = 0;

        $this->retry->run(
            function () use (&$counter) {
                $counter++;
                throw new \RuntimeException('Non-retryable error');
            }
        );

        $this->assertEquals(1, $counter);
    }

    public function test_custom_retry_configuration(): void
    {
        $counter = 0;

        $result = $this->retry
            ->maxRetries(5)
            ->retryDelay(1)
            ->timeout(10)
            ->run($this->createFailingCallback(3, 'Connection timed out', $counter));

        $this->assertEquals('success', $result);
        $this->assertEquals(4, $counter);
    }

    public function test_progress_callback_is_called(): void
    {
        $progressMessages = [];

        try {
            $this->retry
                ->withProgress(function ($message) use (&$progressMessages) {
                    $progressMessages[] = $message;
                })
                ->run($this->createFailingCallback(4));
        } catch (ConnectException $e) {
            // Expected exception
        }

        $this->assertCount(3, $progressMessages);
        $this->assertStringContainsString('Attempt 1 failed', $progressMessages[0]);
        $this->assertStringContainsString('Connection timed out', $progressMessages[0]);
    }

    public function test_custom_patterns_and_exceptions(): void
    {
        $counter = 0;

        $result = $this->retry->run(
            function () use (&$counter) {
                $counter++;
                if ($counter < 2) {
                    throw new \Exception('Custom error pattern match');
                }
                return 'success';
            },
            ['/custom error pattern/i'],
            [\Exception::class]
        );

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $counter);
    }

    public function test_facade_works_correctly(): void
    {
        $result = \GregPriday\LaravelRetry\Facades\Retry::run(
            $this->createFailingCallback(2)
        );

        $this->assertEquals('success', $result);
    }
}