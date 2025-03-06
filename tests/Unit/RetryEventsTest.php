<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Exception;
use GregPriday\LaravelRetry\Events\OperationFailedEvent;
use GregPriday\LaravelRetry\Events\OperationSucceededEvent;
use GregPriday\LaravelRetry\Events\RetryingOperationEvent;
use GregPriday\LaravelRetry\Retry;
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
            return $event->attempt === 0 && $event->result === 'success';
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
            return $event->attempt === 1 && $event->exception !== null;
        });

        // Then a success event when it completes
        Event::assertDispatched(OperationSucceededEvent::class, function ($event) {
            return $event->attempt === 1 && $event->result === 'success after retry';
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
            return $event->attempt === 1;
        });

        Event::assertDispatched(RetryingOperationEvent::class, function ($event) {
            return $event->attempt === 2;
        });

        // And a failure event at the end
        Event::assertDispatched(OperationFailedEvent::class, function ($event) {
            return $event->attempt === 2 && $event->error instanceof Exception;
        });
    }

    public function test_events_are_not_dispatched_when_disabled_in_config(): void
    {
        Event::fake([
            RetryingOperationEvent::class,
            OperationSucceededEvent::class,
            OperationFailedEvent::class,
        ]);

        // Disable event dispatching
        config(['retry.dispatch_events' => false]);

        // Run operation that would normally trigger events
        $attempt = 0;
        $this->retry->run(function () use (&$attempt) {
            if ($attempt < 1) {
                $attempt++;
                throw $this->createGuzzleException('Connection timed out');
            }

            return 'success';
        });

        Event::assertNotDispatched(RetryingOperationEvent::class);
        Event::assertNotDispatched(OperationSucceededEvent::class);
        Event::assertNotDispatched(OperationFailedEvent::class);
    }

    public function test_event_callbacks_are_called(): void
    {
        // Setup the retry with callbacks we can track
        $retryCallback = false;
        $successCallback = false;
        $failureCallback = false;

        $retry = new Retry(maxRetries: 3, retryDelay: 0, timeout: 5);
        $retry->withEventCallbacks([
            'onRetry' => function ($event) use (&$retryCallback) {
                $retryCallback = true;
            },
            'onSuccess' => function ($event) use (&$successCallback) {
                $successCallback = true;
            },
            'onFailure' => function ($event) use (&$failureCallback) {
                $failureCallback = true;
            },
        ]);

        // Test operation that should trigger retry and success
        $attempt = 0;
        $retry->run(function () use (&$attempt) {
            if ($attempt < 1) {
                $attempt++;
                throw $this->createGuzzleException('Connection timed out');
            }

            return 'success';
        });

        $this->assertTrue($retryCallback, 'Retry callback was not called');
        $this->assertTrue($successCallback, 'Success callback was not called');
        $this->assertFalse($failureCallback, 'Failure callback was called unexpectedly');

        // Reset flags
        $retryCallback = false;
        $successCallback = false;
        $failureCallback = false;

        // Create a new instance to avoid any state issues
        $retry = new Retry(maxRetries: 1, retryDelay: 0, timeout: 5);
        $retry->withEventCallbacks([
            'onRetry' => function ($event) use (&$retryCallback) {
                $retryCallback = true;
            },
            'onSuccess' => function ($event) use (&$successCallback) {
                $successCallback = true;
            },
            'onFailure' => function ($event) use (&$failureCallback) {
                $failureCallback = true;
            },
        ]);

        // Test a completely failing operation
        try {
            $retry->run(function () {
                throw $this->createGuzzleException('Always fails');
            });
        } catch (Exception $e) {
            // Expected
        }

        $this->assertTrue($retryCallback, 'Retry callback was not called');
        $this->assertFalse($successCallback, 'Success callback was called unexpectedly');
        $this->assertTrue($failureCallback, 'Failure callback was not called');
    }

    public function test_event_contains_expected_data(): void
    {
        $retryEvent = null;
        $successEvent = null;
        $failureEvent = null;

        // Create a new instance with controlled parameters
        $retry = new Retry(maxRetries: 3, retryDelay: 1, timeout: 5);

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

        // Test retry and success event data
        $attempt = 0;
        $retry->run(function () use (&$attempt) {
            if ($attempt < 1) {
                $attempt++;
                throw $this->createGuzzleException('Test exception');
            }

            return 'success';
        });

        $this->assertNotNull($retryEvent, 'Retry event was not captured');
        $this->assertEquals(1, $retryEvent->attempt);
        $this->assertEquals($retry->getMaxRetries(), $retryEvent->maxRetries);
        $this->assertInstanceOf(\GuzzleHttp\Exception\ConnectException::class, $retryEvent->exception);
        $this->assertEquals('Test exception', $retryEvent->exception->getMessage());

        $this->assertNotNull($successEvent, 'Success event was not captured');
        $this->assertEquals(1, $successEvent->attempt);
        $this->assertEquals('success', $successEvent->result);
        $this->assertIsFloat($successEvent->totalTime);
        $this->assertIsInt($successEvent->timestamp);

        // Reset event references
        $retryEvent = null;
        $successEvent = null;
        $failureEvent = null;

        // Create a new retry instance to avoid any state issues
        $retry = new Retry(maxRetries: 1, retryDelay: 0, timeout: 5);
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

        // Test failure event data
        try {
            $retry->run(function () {
                throw $this->createGuzzleException('Always fails');
            });
        } catch (Exception $e) {
            // Expected
        }

        $this->assertNotNull($retryEvent, 'Retry event was not captured');
        $this->assertNotNull($failureEvent, 'Failure event was not captured');
        $this->assertEquals(1, $failureEvent->attempt);
        $this->assertInstanceOf(\GuzzleHttp\Exception\ConnectException::class, $failureEvent->error);
        $this->assertEquals('Always fails', $failureEvent->error->getMessage());
        $this->assertIsArray($failureEvent->exceptionHistory);
        // The exception history should have at least one entry
        $this->assertGreaterThanOrEqual(1, count($failureEvent->exceptionHistory));
        $this->assertIsInt($failureEvent->timestamp);
    }
}
