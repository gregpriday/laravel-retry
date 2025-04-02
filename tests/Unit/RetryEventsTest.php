<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Exception;
use GregPriday\LaravelRetry\Events\OperationFailedEvent;
use GregPriday\LaravelRetry\Events\OperationSucceededEvent;
use GregPriday\LaravelRetry\Events\RetryingOperationEvent;
use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\RetryContext;
use GregPriday\LaravelRetry\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class RetryEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set the config for testing and ensure it's true
        config(['retry.dispatch_events' => true]);
    }

    public function test_events_are_dispatched_on_retry_success_and_failure(): void
    {
        // Fake the event dispatcher
        Event::fake([
            RetryingOperationEvent::class,
            OperationSucceededEvent::class,
            OperationFailedEvent::class,
        ]);

        // Test successful operation
        $this->retry->run(function () {
            return 'success';
        });

        // Verify successful operation event was dispatched
        Event::assertDispatched(OperationSucceededEvent::class, function ($event) {
            return $event->attempt === 0 &&
                   $event->result === 'success' &&
                   $event->context instanceof RetryContext;
        });

        // Test failing operation that gets retried once and then succeeds
        $attempt = 0;
        $this->retry->run(function () use (&$attempt) {
            if ($attempt < 1) {
                $attempt++;
                // Use a Guzzle exception which is known to be retryable
                throw $this->createGuzzleException('Connection timed out');
            }

            return 'success after retry';
        });

        // This should cause a retry event to be dispatched
        Event::assertDispatched(RetryingOperationEvent::class, function ($event) {
            return $event->attempt === 1 &&
                   $event->exception !== null &&
                   $event->context instanceof RetryContext;
        });

        // Then a success event when it completes
        Event::assertDispatched(OperationSucceededEvent::class, function ($event) {
            return $event->attempt === 1 &&
                   $event->result === 'success after retry' &&
                   $event->context instanceof RetryContext;
        });

        // Test completely failing operation (using a new retry instance to avoid hanging state)
        $retry = new Retry(maxRetries: 2, retryDelay: 0, timeout: 5);

        try {
            $retry->run(function () {
                throw $this->createGuzzleException('Always fails');
            });
        } catch (Exception $e) {
            // Expected
        }

        // Should have retry events for each attempt
        Event::assertDispatched(RetryingOperationEvent::class, function ($event) {
            return $event->attempt === 1 && $event->context instanceof RetryContext;
        });

        Event::assertDispatched(RetryingOperationEvent::class, function ($event) {
            return $event->attempt === 2 && $event->context instanceof RetryContext;
        });

        // And a failure event at the end
        Event::assertDispatched(OperationFailedEvent::class, function ($event) {
            return $event->attempt === 2 &&
                   $event->error instanceof Exception &&
                   $event->context instanceof RetryContext;
        });
    }

    public function test_events_contain_metrics_and_metadata(): void
    {
        $retryEvent = null;
        $retry = new Retry(maxRetries: 2, retryDelay: 0, timeout: 5);

        // Set up metadata before running the operation
        $retry->withMetadata(['test_key' => 'test_value']);

        $retry->withEventCallbacks([
            'onRetry' => function (RetryingOperationEvent $event) use (&$retryEvent) {
                $retryEvent = $event;
            },
        ]);

        $attempt = 0;
        try {
            $retry->run(function () use (&$attempt) {
                if ($attempt === 0) {
                    $attempt++;
                    throw $this->createGuzzleException('Test error');
                }

                return 'success';
            });
        } catch (Exception $e) {
            // Expected
        }

        $this->assertNotNull($retryEvent);
        $context = $retryEvent->getContext();

        // Check metrics
        $metrics = $context->getMetrics();
        $this->assertArrayHasKey('total_duration', $metrics);
        $this->assertArrayHasKey('average_attempt_duration', $metrics);
        $this->assertArrayHasKey('min_attempt_duration', $metrics);
        $this->assertArrayHasKey('max_attempt_duration', $metrics);
        $this->assertArrayHasKey('total_elapsed_time', $metrics);

        // Check metadata
        $metadata = $context->getMetadata();
        $this->assertArrayHasKey('test_key', $metadata);
        $this->assertEquals('test_value', $metadata['test_key']);

        // Check exception history
        $history = $context->getExceptionHistory();
        $this->assertCount(1, $history);
        $this->assertArrayHasKey('duration', $history[0]);
        $this->assertArrayHasKey('delay', $history[0]);
    }

    public function test_event_summaries_contain_expected_data(): void
    {
        $retryEvent = null;
        $successEvent = null;
        $failureEvent = null;

        $retry = new Retry(maxRetries: 2, retryDelay: 0, timeout: 5);

        // Set up metadata before running the operation
        $retry->withMetadata(['source' => 'test']);

        $retry->withEventCallbacks([
            'onRetry' => function (RetryingOperationEvent $event) use (&$retryEvent) {
                $retryEvent = $event;
            },
            'onSuccess' => function (OperationSucceededEvent $event) use (&$successEvent) {
                $successEvent = $event;
            },
            'onFailure' => function (OperationFailedEvent $event) use (&$failureEvent) {
                $failureEvent = $event;
            },
        ]);

        $attempt = 0;
        try {
            $retry->run(function () use (&$attempt) {
                if ($attempt === 0) {
                    $attempt++;
                    throw $this->createGuzzleException('Test error');
                }

                return 'success';
            });
        } catch (Exception $e) {
            // Expected
        }

        $this->assertNotNull($retryEvent);
        $context = $retryEvent->getContext();

        // Check metrics
        $metrics = $context->getMetrics();
        $this->assertArrayHasKey('total_duration', $metrics);
        $this->assertArrayHasKey('average_attempt_duration', $metrics);
        $this->assertArrayHasKey('min_attempt_duration', $metrics);
        $this->assertArrayHasKey('max_attempt_duration', $metrics);
        $this->assertArrayHasKey('total_elapsed_time', $metrics);

        // Check metadata
        $metadata = $context->getMetadata();
        $this->assertEquals('test', $metadata['source']);

        // Check exception history
        $history = $context->getExceptionHistory();
        $this->assertCount(1, $history);
        $this->assertArrayHasKey('duration', $history[0]);
        $this->assertArrayHasKey('delay', $history[0]);

        // Check retry event summary
        $this->assertNotNull($retryEvent);
        $summary = $retryEvent->getSummary();
        $this->assertArrayHasKey('operation_id', $summary);
        $this->assertArrayHasKey('metrics', $summary);
        $this->assertArrayHasKey('metadata', $summary);
        $this->assertEquals('test', $summary['metadata']['source']);

        // Check success event summary
        $this->assertNotNull($successEvent);
        $summary = $successEvent->getSummary();
        $this->assertArrayHasKey('operation_id', $summary);
        $this->assertArrayHasKey('result_type', $summary);
        $this->assertEquals('string', $summary['result_type']);

        // Reset events
        $retryEvent = null;
        $successEvent = null;
        $failureEvent = null;

        // Test failure event data
        try {
            $retry->run(function () {
                throw $this->createGuzzleException('Always fails');
            });
        } catch (Exception $e) {
            // Expected
        }

        // Check failure event summary
        $this->assertNotNull($failureEvent);
        $summary = $failureEvent->getSummary();
        $this->assertArrayHasKey('operation_id', $summary);
        $this->assertArrayHasKey('error', $summary);
        $this->assertArrayHasKey('exception_history', $summary);
        $this->assertArrayHasKey('metrics', $summary);
        $this->assertArrayHasKey('metadata', $summary);

        // Check exception history format
        $history = $summary['exception_history'];
        $this->assertNotEmpty($history);
        $this->assertArrayHasKey('exception', $history[0]);
        $this->assertArrayHasKey('delay', $history[0]);
        $this->assertArrayHasKey('duration', $history[0]);
    }

    public function test_context_persists_across_attempts(): void
    {
        $retry = new Retry(maxRetries: 2, retryDelay: 0, timeout: 5);
        $retry->withMetadata(['initial' => true]);

        $attempt = 0;
        $retry->run(function () use (&$attempt, $retry) {
            if ($attempt === 0) {
                $retry->withMetadata(['during_first' => true]);
            }
            if ($attempt < 1) {
                $attempt++;
                throw $this->createGuzzleException('Test exception');
            }
            $retry->withMetadata(['during_second' => true]);

            return 'success';
        });

        $metadata = $retry->getContext()->getMetadata();
        $this->assertTrue($metadata['initial']);
        $this->assertTrue($metadata['during_first']);
        $this->assertTrue($metadata['during_second']);
    }
}
