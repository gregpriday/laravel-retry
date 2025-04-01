<?php

namespace GregPriday\LaravelRetry\Tests;

use Exception;
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Exceptions\Handlers\GuzzleHandler;
use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\RetryServiceProvider;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Mockery;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;

// Define a constant to indicate we're running in PHPUnit
if (! defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

abstract class TestCase extends OrchestraTestCase
{
    protected Retry $retry;

    protected ExceptionHandlerManager $exceptionManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Create and configure exception manager
        $this->exceptionManager = new ExceptionHandlerManager;

        // Explicitly register the GuzzleHandler
        $guzzleHandler = new GuzzleHandler;
        if ($guzzleHandler->isApplicable()) {
            $this->exceptionManager->registerHandler($guzzleHandler);
        }

        // Verify handler registration
        if (! $this->exceptionManager->hasHandler(GuzzleHandler::class)) {
            throw new RuntimeException('GuzzleHandler was not properly registered');
        }

        // Create retry instance with explicit configuration
        $this->retry = new Retry(
            maxRetries: 3,
            retryDelay: 0, // Set to 0 for faster tests
            timeout: 5,
            exceptionManager: $this->exceptionManager
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            RetryServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('retry.max_retries', 3);
        config()->set('retry.delay', 0); // Set to 0 for faster tests
        config()->set('retry.timeout', 5);
    }

    /**
     * Create a callback that will fail n times before succeeding.
     */
    protected function createFailingCallback(
        int $failCount,
        string $exceptionMessage = 'Connection timed out',
        &$counter = 0
    ): callable {
        $counter = 0; // Initialize counter

        return function () use ($failCount, $exceptionMessage, &$counter) {
            $counter++;
            if ($counter <= $failCount) {
                throw $this->createGuzzleException($exceptionMessage);
            }

            return 'success';
        };
    }

    /**
     * Create a new retryable exception with the given message.
     */
    protected function createGuzzleException(string $message): ConnectException
    {
        return new ConnectException(
            $message,
            new Request('GET', 'http://example.com')
        );
    }

    protected function assertValidMetrics(array $metrics): void
    {
        $requiredMetrics = [
            'total_duration',
            'total_delay',
            'average_attempt_duration',
            'min_attempt_duration',
            'max_attempt_duration',
            'total_elapsed_time',
        ];

        foreach ($requiredMetrics as $metric) {
            $this->assertArrayHasKey($metric, $metrics, "Missing required metric: {$metric}");
            $this->assertIsFloat($metrics[$metric], "Metric {$metric} should be a float");
            $this->assertGreaterThanOrEqual(0, $metrics[$metric], "Metric {$metric} should be non-negative");
        }
    }

    protected function assertValidExceptionHistory(array $history): void
    {
        foreach ($history as $attempt) {
            $this->assertArrayHasKey('exception', $attempt, 'Exception history entry should have an exception');
            $this->assertArrayHasKey('duration', $attempt, 'Exception history entry should have a duration');
            $this->assertArrayHasKey('delay', $attempt, 'Exception history entry should have a delay');
            $this->assertInstanceOf(Exception::class, $attempt['exception']);
            $this->assertIsFloat($attempt['duration']);
            $this->assertIsFloat($attempt['delay']);
        }
    }

    protected function assertValidContext(\GregPriday\LaravelRetry\RetryContext $context): void
    {
        // Check basic properties
        $this->assertIsString($context->getOperationId());
        $this->assertIsArray($context->getMetrics());
        $this->assertIsArray($context->getMetadata());
        $this->assertIsArray($context->getExceptionHistory());

        // Validate metrics
        $this->assertValidMetrics($context->getMetrics());

        // Validate exception history if any exists
        if (! empty($context->getExceptionHistory())) {
            $this->assertValidExceptionHistory($context->getExceptionHistory());
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
