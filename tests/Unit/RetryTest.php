<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Exception;
use GregPriday\LaravelRetry\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use RuntimeException;

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
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Non-retryable error');

        $counter = 0;

        $this->retry->run(
            function () use (&$counter) {
                $counter++;
                throw new RuntimeException('Non-retryable error');
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
                    throw new Exception('Custom error pattern match');
                }

                return 'success';
            },
            ['/custom error pattern/i'],
            [Exception::class]
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

    public function test_exception_history_is_empty_on_successful_first_attempt(): void
    {
        $this->retry->run(function () {
            return 'success';
        });

        $this->assertEmpty($this->retry->getExceptionHistory());
        $this->assertEquals(0, $this->retry->getExceptionCount());
        $this->assertEquals(0, $this->retry->getRetryableExceptionCount());
    }

    public function test_exception_history_tracks_retryable_exceptions(): void
    {
        $counter = 0;

        $result = $this->retry->run($this->createFailingCallback(2));

        $history = $this->retry->getExceptionHistory();

        $this->assertCount(2, $history);
        $this->assertEquals(2, $this->retry->getExceptionCount());
        $this->assertEquals(2, $this->retry->getRetryableExceptionCount());

        // Verify first exception
        $this->assertEquals(0, $history[0]['attempt']);
        $this->assertInstanceOf(ConnectException::class, $history[0]['exception']);
        $this->assertTrue($history[0]['was_retryable']);
        $this->assertIsInt($history[0]['timestamp']);

        // Verify second exception
        $this->assertEquals(1, $history[1]['attempt']);
        $this->assertInstanceOf(ConnectException::class, $history[1]['exception']);
        $this->assertTrue($history[1]['was_retryable']);
        $this->assertIsInt($history[1]['timestamp']);
    }

    public function test_exception_history_tracks_non_retryable_exceptions(): void
    {
        try {
            $this->retry->run(function () {
                throw new RuntimeException('Non-retryable error');
            });
        } catch (RuntimeException $e) {
            // Expected exception
        }

        $history = $this->retry->getExceptionHistory();

        $this->assertCount(1, $history);
        $this->assertEquals(1, $this->retry->getExceptionCount());
        $this->assertEquals(0, $this->retry->getRetryableExceptionCount());

        $this->assertEquals(0, $history[0]['attempt']);
        $this->assertInstanceOf(RuntimeException::class, $history[0]['exception']);
        $this->assertFalse($history[0]['was_retryable']);
        $this->assertEquals('Non-retryable error', $history[0]['exception']->getMessage());
    }

    public function test_exception_history_resets_between_runs(): void
    {
        // First run with failures
        try {
            $this->retry->run($this->createFailingCallback(4));
        } catch (ConnectException $e) {
            // Expected exception
        }

        $this->assertCount(3, $this->retry->getExceptionHistory());

        // Second run with success
        $this->retry->run(function () {
            return 'success';
        });

        $this->assertEmpty($this->retry->getExceptionHistory());
    }

    public function test_exception_history_with_mixed_exceptions(): void
    {
        $attempt = 0;

        try {
            $this->retry->run(function () use (&$attempt) {
                $attempt++;
                if ($attempt === 1) {
                    throw new ConnectException('Connection timed out', new \GuzzleHttp\Psr7\Request('GET', 'http://example.com'));
                }
                if ($attempt === 2) {
                    throw new RuntimeException('Non-retryable error');
                }

                return 'success';
            });
        } catch (RuntimeException $e) {
            // Expected exception
        }

        $history = $this->retry->getExceptionHistory();

        $this->assertCount(2, $history);
        $this->assertEquals(2, $this->retry->getExceptionCount());
        $this->assertEquals(1, $this->retry->getRetryableExceptionCount());

        // First exception should be retryable
        $this->assertTrue($history[0]['was_retryable']);
        $this->assertInstanceOf(ConnectException::class, $history[0]['exception']);

        // Second exception should not be retryable
        $this->assertFalse($history[1]['was_retryable']);
        $this->assertInstanceOf(RuntimeException::class, $history[1]['exception']);
    }

    public function test_exception_history_timestamps_are_sequential(): void
    {
        try {
            $this->retry->run($this->createFailingCallback(2));
        } catch (ConnectException $e) {
            // Expected exception
        }

        $history = $this->retry->getExceptionHistory();
        $this->assertGreaterThanOrEqual(2, count($history));

        // Verify timestamps are sequential
        for ($i = 1; $i < count($history); $i++) {
            $this->assertGreaterThanOrEqual(
                $history[$i - 1]['timestamp'],
                $history[$i]['timestamp'],
                'Exception history timestamps should be sequential'
            );
        }
    }
}
