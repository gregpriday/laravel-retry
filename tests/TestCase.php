<?php

namespace GregPriday\LaravelRetry\Tests;

use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Exceptions\Handlers\GuzzleHandler;
use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\RetryServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected Retry $retry;

    protected ExceptionHandlerManager $exceptionManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Create and configure exception manager
        $this->exceptionManager = new ExceptionHandlerManager();

        // Explicitly register the GuzzleHandler
        $guzzleHandler = new GuzzleHandler();
        if ($guzzleHandler->isApplicable()) {
            $this->exceptionManager->registerHandler($guzzleHandler);
        }

        // Verify handler registration
        if (! $this->exceptionManager->hasHandler(GuzzleHandler::class)) {
            throw new \RuntimeException('GuzzleHandler was not properly registered');
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
    protected function createGuzzleException(string $message): \GuzzleHttp\Exception\ConnectException
    {
        return new \GuzzleHttp\Exception\ConnectException(
            $message,
            new \GuzzleHttp\Psr7\Request('GET', 'http://example.com')
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
