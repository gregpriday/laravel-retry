<?php

namespace GregPriday\LaravelRetry\Tests;

use GregPriday\LaravelRetry\RetryServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use Mockery;

abstract class TestCase extends OrchestraTestCase
{
    protected Retry $retry;
    protected ExceptionHandlerManager $exceptionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exceptionManager = new ExceptionHandlerManager();
        $this->exceptionManager->registerDefaultHandlers();

        $this->retry = new Retry(
            maxRetries: 3,
            retryDelay: 1,
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
        config()->set('retry.delay', 1);
        config()->set('retry.timeout', 5);
    }

    /**
     * Create a callback that will fail n times before succeeding.
     */
    protected function createFailingCallback(
        int $failCount,
        string $exceptionMessage = 'Connection timed out',
        &$counter = null
    ): callable {
        return function () use ($failCount, $exceptionMessage, &$counter) {
            if ($counter !== null) {
                $counter++;
            }

            if ($counter === null || $counter <= $failCount) {
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