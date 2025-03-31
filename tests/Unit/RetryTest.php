<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Exception;
use GregPriday\LaravelRetry\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use RuntimeException;
use Throwable;

class RetryTest extends TestCase
{
    public function test_successful_operation_executes_once(): void
    {
        $counter = 0;

        $result = $this->retry->run(function () use (&$counter) {
            $counter++;

            return 'success';
        })->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $counter);
    }

    public function test_operation_retries_on_retryable_exception(): void
    {
        $result = $this->retry->run($this->createFailingCallback(2))->value();

        $this->assertEquals('success', $result);
    }

    public function test_operation_fails_after_max_retries(): void
    {
        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection timed out');

        $this->retry->run($this->createFailingCallback(5))->throw();
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
        )->throw();

        $this->assertEquals(1, $counter);
    }

    public function test_custom_retry_configuration(): void
    {
        $counter = 0;

        $result = $this->retry
            ->maxRetries(5)
            ->retryDelay(1)
            ->timeout(10)
            ->run($this->createFailingCallback(3, 'Connection timed out', $counter))
            ->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(4, $counter);
    }

    public function test_progress_callback_is_called(): void
    {
        $progressMessages = [];

        $result = $this->retry
            ->withProgress(function ($message) use (&$progressMessages) {
                $progressMessages[] = $message;
            })
            ->run($this->createFailingCallback(4));

        $this->assertTrue($result->failed());
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
        )->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $counter);
    }

    public function test_facade_works_correctly(): void
    {
        $result = \GregPriday\LaravelRetry\Facades\Retry::run(
            $this->createFailingCallback(2)
        )->value();

        $this->assertEquals('success', $result);
    }

    public function test_exception_history_is_empty_on_successful_first_attempt(): void
    {
        $result = $this->retry->run(function () {
            return 'success';
        });

        $this->assertTrue($result->succeeded());
        $this->assertEmpty($result->getExceptionHistory());
    }

    public function test_exception_history_tracks_retryable_exceptions(): void
    {
        $result = $this->retry->run($this->createFailingCallback(2));

        $history = $result->getExceptionHistory();

        $this->assertCount(2, $history);

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
        $result = $this->retry->run(function () {
            throw new RuntimeException('Non-retryable error');
        });

        $history = $result->getExceptionHistory();

        $this->assertCount(1, $history);
        $this->assertTrue($result->failed());

        $this->assertEquals(0, $history[0]['attempt']);
        $this->assertInstanceOf(RuntimeException::class, $history[0]['exception']);
        $this->assertFalse($history[0]['was_retryable']);
        $this->assertEquals('Non-retryable error', $history[0]['exception']->getMessage());
    }

    public function test_exception_history_resets_between_runs(): void
    {
        // First run with failures
        $result1 = $this->retry->run($this->createFailingCallback(4));
        // Now expecting 4 attempts due to changes in the retry behavior
        $this->assertCount(4, $result1->getExceptionHistory());

        // Second run with success
        $result2 = $this->retry->run(function () {
            return 'success';
        });
        $this->assertEmpty($result2->getExceptionHistory());
    }

    public function test_exception_history_with_mixed_exceptions(): void
    {
        $attempt = 0;

        $result = $this->retry->run(function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                throw new ConnectException(
                    'Connection timed out',
                    new \GuzzleHttp\Psr7\Request('GET', 'http://example.com')
                );
            }
            if ($attempt === 2) {
                throw new RuntimeException('Non-retryable error');
            }

            return 'success';
        });

        $history = $result->getExceptionHistory();

        $this->assertCount(2, $history);
        $this->assertTrue($result->failed());

        // First exception should be retryable
        $this->assertTrue($history[0]['was_retryable']);
        $this->assertInstanceOf(ConnectException::class, $history[0]['exception']);

        // Second exception should not be retryable
        $this->assertFalse($history[1]['was_retryable']);
        $this->assertInstanceOf(RuntimeException::class, $history[1]['exception']);
    }

    public function test_exception_history_timestamps_are_sequential(): void
    {
        $result = $this->retry->run($this->createFailingCallback(2));
        $history = $result->getExceptionHistory();

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

    public function test_retry_if_condition_controls_retry_behavior(): void
    {
        $counter = 0;
        $this->retry->maxRetries(5); // Ensure we have enough retries

        $result = $this->retry
            ->retryIf(function (Throwable $e, array $context) {
                // Always retry for first two attempts, then stop
                return $context['attempt'] < 2;
            })
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 3) {
                    throw new ConnectException(
                        'Connection timed out',
                        new \GuzzleHttp\Psr7\Request('GET', 'http://example.com')
                    );
                }

                return 'success';
            })->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $counter);

        $history = $this->retry->getExceptionHistory();
        $this->assertCount(2, $history);

        // First attempt (attempt 0) should be retryable
        $this->assertTrue($history[0]['was_retryable']);

        // Second attempt (attempt 1) should be retryable
        $this->assertTrue($history[1]['was_retryable']);
    }

    public function test_retry_unless_condition_controls_retry_behavior(): void
    {
        $counter = 0;
        $this->retry->maxRetries(5); // Ensure we have enough retries

        $result = $this->retry
            ->retryUnless(function (Throwable $e, array $context) {
                // Stop retrying after seeing the same error twice
                $sameErrorCount = count(array_filter(
                    $context['exception_history'],
                    fn ($entry) => $entry['exception']->getMessage() === 'Connection timed out'
                ));

                return $sameErrorCount >= 2;
            })
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 3) {
                    throw new ConnectException(
                        'Connection timed out',
                        new \GuzzleHttp\Psr7\Request('GET', 'http://example.com')
                    );
                }

                return 'success';
            })->value();

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $counter);

        $history = $this->retry->getExceptionHistory();
        $this->assertCount(2, $history);

        // First attempt should be retryable
        $this->assertTrue($history[0]['was_retryable']);

        // Second attempt should be retryable
        $this->assertTrue($history[1]['was_retryable']);
    }

    public function test_retry_unless_stops_retrying_when_condition_met(): void
    {
        $this->expectException(ConnectException::class);

        $this->retry
            ->retryUnless(function (Throwable $e, array $context) {
                return count($context['exception_history']) >= 2;
            })
            ->run(function () {
                throw new ConnectException(
                    'Connection timed out',
                    new \GuzzleHttp\Psr7\Request('GET', 'http://example.com')
                );
            })->throw();
    }

    public function test_retry_if_receives_correct_context_data(): void
    {
        $contextData = [];

        $result = $this->retry
            ->maxRetries(3)
            ->retryIf(function (Throwable $e, array $context) use (&$contextData) {
                $contextData[] = $context;

                return true;
            })
            ->run($this->createFailingCallback(4));

        $this->assertTrue($result->failed());
        // Now expecting 4 attempts due to changes in the retry behavior
        $this->assertCount(4, $contextData);

        // Verify first context
        $this->assertEquals([
            'attempt'            => 0,
            'max_retries'        => 3,
            'remaining_attempts' => 3,
            'exception_history'  => [],
        ], $contextData[0]);

        // Verify second context
        $this->assertEquals(1, $contextData[1]['attempt']);
        $this->assertEquals(3, $contextData[1]['max_retries']);
        $this->assertEquals(2, $contextData[1]['remaining_attempts']);
        $this->assertCount(1, $contextData[1]['exception_history']);

        // Verify third context
        $this->assertEquals(2, $contextData[2]['attempt']);
        $this->assertEquals(3, $contextData[2]['max_retries']);
        $this->assertEquals(1, $contextData[2]['remaining_attempts']);
        $this->assertCount(2, $contextData[2]['exception_history']);
        
        // Verify fourth context
        $this->assertEquals(3, $contextData[3]['attempt']);
        $this->assertEquals(3, $contextData[3]['max_retries']);
        $this->assertEquals(0, $contextData[3]['remaining_attempts']);
        $this->assertCount(3, $contextData[3]['exception_history']);
    }

    public function test_retry_condition_can_override_standard_retry_rules(): void
    {
        $counter = 0;

        $result = $this->retry
            ->retryIf(function (Throwable $e, array $context) {
                return false; // Never retry
            })
            ->run(function () use (&$counter) {
                $counter++;
                throw new ConnectException(
                    'Connection timed out',
                    new \GuzzleHttp\Psr7\Request('GET', 'http://example.com')
                );
            });

        $this->assertTrue($result->failed());
        $this->assertEquals(1, $counter);

        $history = $result->getExceptionHistory();
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['was_retryable']);
    }
}
