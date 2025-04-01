<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Exception;
use GregPriday\LaravelRetry\RetryContext;
use GregPriday\LaravelRetry\Tests\TestCase;

class RetryContextTest extends TestCase
{
    private RetryContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new RetryContext(
            maxRetries: 3,
            startTime: microtime(true)
        );
    }

    public function test_context_initialization(): void
    {
        $this->assertNotEmpty($this->context->getOperationId());
        $this->assertEmpty($this->context->getMetadata());
        $this->assertEmpty($this->context->getExceptionHistory());
        $this->assertIsArray($this->context->getMetrics());
    }

    public function test_metadata_management(): void
    {
        // Test adding metadata
        $this->context->addMetadata(['key' => 'value']);
        $this->assertEquals(['key' => 'value'], $this->context->getMetadata());

        // Test merging additional metadata
        $this->context->addMetadata(['another_key' => 'another_value']);
        $expected = [
            'key'         => 'value',
            'another_key' => 'another_value',
        ];
        $this->assertEquals($expected, $this->context->getMetadata());

        // Test overwriting existing metadata
        $this->context->addMetadata(['key' => 'new_value']);
        $expected['key'] = 'new_value';
        $this->assertEquals($expected, $this->context->getMetadata());

        // Test getting specific metadata value
        $this->assertEquals('new_value', $this->context->getMetadataValue('key'));
        $this->assertEquals('default', $this->context->getMetadataValue('non_existent', 'default'));
    }

    public function test_metrics_management(): void
    {
        // Record successful attempt with duration
        $this->context->recordAttempt(0, null, false, null, 1.5);
        $this->context->recordAttempt(1, null, false, null, 2.0);
        $this->context->recordAttempt(2, null, false, null, 1.0);

        $metrics = $this->context->getMetrics();

        $this->assertEquals(4.5, $metrics['total_duration']);
        $this->assertEquals(1.5, $metrics['average_attempt_duration']);
        $this->assertEquals(1.0, $metrics['min_attempt_duration']);
        $this->assertEquals(2.0, $metrics['max_attempt_duration']);

        // Record attempts with delays
        $exception = $this->createGuzzleException('Test error');
        $this->context->recordAttempt(3, $exception, true, 1, 1.0);
        $this->context->recordAttempt(4, $exception, true, 2, 1.0);

        $metrics = $this->context->getMetrics();
        $this->assertEquals(6.5, $metrics['total_duration']); // Previous 4.5 + two new 1.0 durations
        $this->assertGreaterThan(0, $metrics['total_elapsed_time']); // Should be greater than the sum of durations and delays
    }

    public function test_exception_history_management(): void
    {
        $exception1 = $this->createGuzzleException('First error');
        $exception2 = $this->createGuzzleException('Second error');

        // Record first attempt with exception
        $this->context->recordAttempt(0, $exception1, true, 1.0, 1.5);

        $history = $this->context->getExceptionHistory();
        $this->assertCount(1, $history);
        $this->assertSame($exception1, $history[0]['exception']);
        $this->assertEquals(1.5, $history[0]['duration']);
        $this->assertEquals(1.0, $history[0]['delay']);
        $this->assertTrue($history[0]['was_retryable']);

        // Record second attempt with exception
        $this->context->recordAttempt(1, $exception2, false, 1.5, 2.0);

        $history = $this->context->getExceptionHistory();
        $this->assertCount(2, $history);
        $this->assertSame($exception2, $history[1]['exception']);
        $this->assertEquals(2.0, $history[1]['duration']);
        $this->assertEquals(1.5, $history[1]['delay']);
        $this->assertFalse($history[1]['was_retryable']);

        // Record successful attempt (should not add to exception history)
        $this->context->recordAttempt(2, null, false, null, 1.0);
        $this->assertCount(2, $history);
    }

    public function test_context_immutability(): void
    {
        // Initial state
        $this->context->addMetadata(['initial' => true]);
        $this->context->recordAttempt(0, null, false, null, 1.0);
        $exception = $this->createGuzzleException('Test');
        $this->context->recordAttempt(1, $exception, true, 0.5, 1.0);

        // Create a snapshot of the current state
        $initialMetadata = $this->context->getMetadata();
        $initialMetrics = $this->context->getMetrics();
        $initialHistory = $this->context->getExceptionHistory();

        // Modify the arrays we got back
        $initialMetadata['modified'] = true;
        $initialMetrics['new_metric'] = 100;
        $initialHistory[] = ['exception' => new Exception, 'duration' => 0, 'delay' => 0];

        // Verify the internal state hasn't changed
        $this->assertArrayNotHasKey('modified', $this->context->getMetadata());
        $this->assertArrayNotHasKey('new_metric', $this->context->getMetrics());
        $this->assertCount(1, $this->context->getExceptionHistory());
    }

    public function test_total_attempts_calculation(): void
    {
        $this->assertEquals(1, $this->context->getTotalAttempts()); // Initial state

        // Add some attempts
        $exception = $this->createGuzzleException('Test error');
        $this->context->recordAttempt(0, $exception, true, 1, 1.0);
        $this->assertEquals(2, $this->context->getTotalAttempts());

        $this->context->recordAttempt(1, null, false, null, 1.0); // Successful attempt
        $this->assertEquals(3, $this->context->getTotalAttempts());
    }

    public function test_total_delay_calculation(): void
    {
        $this->assertEquals(0.0, $this->context->getTotalDelay()); // Initial state

        // Add some attempts with delays
        $exception = $this->createGuzzleException('Test error');
        $this->context->recordAttempt(0, $exception, true, 1.5, 1.0);
        $this->context->recordAttempt(1, $exception, true, 1.5, 1.0);

        $this->assertEquals(3.0, $this->context->getTotalDelay());
    }
}
