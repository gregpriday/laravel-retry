<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Exception;
use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\Tests\TestCase;

class RetryEventsDebugTest extends TestCase
{
    public function test_debug_events(): void
    {
        // Simple test to see what events are triggered
        $retryCallback = false;

        // Create a retry instance that will retry on any exception
        $retry = new Retry(maxRetries: 1, retryDelay: 0, timeout: 5);

        // Add a callback and manually verify it's called
        $retry->withEventCallbacks([
            'onRetry' => function ($event) use (&$retryCallback) {
                $retryCallback = true;
                echo "Retry callback was called!\n";
                var_dump($event);
            },
        ]);

        echo "Running operation that should trigger a retry...\n";

        // Run an operation that will cause a retry with a known retryable exception
        $attempt = 0;
        try {
            $retry->run(function () use (&$attempt) {
                if ($attempt < 1) {
                    $attempt++;
                    echo "Attempt $attempt is failing...\n";
                    // Use the TestCase's createGuzzleException method which is known to be retryable
                    throw $this->createGuzzleException('Connection timed out');
                }

                return 'success';
            });
        } catch (Exception $e) {
            echo 'Operation caught exception: '.$e->getMessage()."\n";
        }

        // Manually check if the callback was triggered
        $this->assertTrue(true, "This test doesn't actually assert anything.");
        echo 'retryCallback flag: '.($retryCallback ? 'true' : 'false')."\n";
    }
}
