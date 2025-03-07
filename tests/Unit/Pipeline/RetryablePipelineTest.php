<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Pipeline;

use Exception;
use GregPriday\LaravelRetry\Pipeline\RetryablePipeline;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use RuntimeException;

class RetryablePipelineTest extends TestCase
{
    public function test_pipeline_with_successful_pipes()
    {
        $pipeline = new RetryablePipeline;

        $result = $pipeline
            ->send(0)
            ->through([
                function ($value, $next) {
                    return $next($value + 1);
                },
                function ($value, $next) {
                    return $next($value * 2);
                },
                function ($value, $next) {
                    return $next($value + 3);
                },
            ])
            ->then(function ($value) {
                return $value;
            });

        $this->assertEquals(5, $result); // (0 + 1) * 2 + 3 = 5
    }

    public function test_pipeline_with_retry_on_failure()
    {
        $pipeline = new RetryablePipeline;
        $attemptCount = 0;

        $result = $pipeline
            ->maxRetries(2)
            ->send(1)
            ->through([
                function ($value, $next) {
                    return $next($value + 1);
                },
                function ($value, $next) use (&$attemptCount) {
                    $attemptCount++;

                    if ($attemptCount < 2) {
                        throw new RuntimeException('Simulated failure');
                    }

                    return $next($value * 3);
                },
                function ($value, $next) {
                    return $next($value + 2);
                },
            ])
            ->then(function ($value) {
                return $value;
            });

        $this->assertEquals(2, $attemptCount);
        $this->assertEquals(8, $result); // (1 + 1) * 3 + 2 = 8
    }

    public function test_pipe_with_custom_retry_settings()
    {
        $retryPipe = new class
        {
            public $retryCount = 3;

            public $retryDelay = 0; // No delay for testing

            public function handle($value, $next)
            {
                static $attempts = 0;
                $attempts++;

                if ($attempts < 3) {
                    throw new RuntimeException("Failed attempt {$attempts}");
                }

                return $next($value * 5);
            }
        };

        $pipeline = new RetryablePipeline;

        $result = $pipeline
            ->maxRetries(1) // Pipeline default is 1, but pipe overrides to 3
            ->send(2)
            ->through([
                function ($value, $next) {
                    return $next($value + 1);
                },
                $retryPipe,
                function ($value, $next) {
                    return $next($value + 2);
                },
            ])
            ->then(function ($value) {
                return $value;
            });

        $this->assertEquals(17, $result); // (2 + 1) * 5 + 2 = 17
    }

    public function test_pipeline_with_custom_strategy()
    {
        $pipeline = new RetryablePipeline;
        $strategy = new ExponentialBackoffStrategy;
        $attemptCount = 0;

        $result = $pipeline
            ->maxRetries(2)
            ->retryDelay(0) // No delay for testing
            ->withStrategy($strategy)
            ->send(1)
            ->through([
                function ($value, $next) use (&$attemptCount) {
                    $attemptCount++;

                    if ($attemptCount < 2) {
                        throw new RuntimeException('Simulated failure');
                    }

                    return $next($value * 2);
                },
            ])
            ->then(function ($value) {
                return $value;
            });

        $this->assertEquals(2, $attemptCount);
        $this->assertEquals(2, $result); // 1 * 2 = 2
    }

    public function test_pipeline_with_progress_callback()
    {
        $pipeline = new RetryablePipeline;
        $progressMessages = [];
        $attemptCount = 0;

        $result = $pipeline
            ->maxRetries(2)
            ->retryDelay(0) // No delay for testing
            ->withProgress(function ($message) use (&$progressMessages) {
                $progressMessages[] = $message;
            })
            ->send(1)
            ->through([
                function ($value, $next) use (&$attemptCount) {
                    $attemptCount++;

                    if ($attemptCount === 1) {
                        throw new RuntimeException('Value too small');
                    }

                    return $next($value * 2);
                },
            ])
            ->then(function ($value) {
                return $value;
            });

        $this->assertEquals(2, $attemptCount);
        $this->assertEquals(2, $result); // 1 * 2 = 2
        $this->assertNotEmpty($progressMessages);
        $this->assertStringContainsString('Exception caught', $progressMessages[0]);
    }

    public function test_pipeline_with_additional_patterns()
    {
        $pipeline = new RetryablePipeline;
        $attemptCount = 0;

        $result = $pipeline
            ->maxRetries(2)
            ->retryDelay(0) // No delay for testing
            ->withAdditionalPatterns(['/custom pattern/i'])
            ->send(1)
            ->through([
                function ($value, $next) use (&$attemptCount) {
                    $attemptCount++;

                    if ($attemptCount < 2) {
                        throw new RuntimeException('Error with custom pattern');
                    }

                    return $next($value * 2);
                },
            ])
            ->then(function ($value) {
                return $value;
            });

        $this->assertEquals(2, $attemptCount);
        $this->assertEquals(2, $result); // 1 * 2 = 2
    }

    public function test_pipe_with_additional_patterns_and_exceptions()
    {
        // Custom exception for testing
        $customException = new class('Test exception') extends Exception {};

        $retryPipe = new class
        {
            public $retryCount = 3;

            public $retryDelay = 0; // No delay for testing

            public $additionalPatterns = ['/custom pipe pattern/i'];

            public $additionalExceptions = [Exception::class];

            public function handle($value, $next)
            {
                static $attempts = 0;
                $attempts++;

                if ($attempts < 3) {
                    throw new Exception('Custom pipe exception');
                }

                return $next($value * 5);
            }
        };

        $pipeline = new RetryablePipeline;

        $result = $pipeline
            ->maxRetries(1) // Pipeline default is 1, but pipe overrides to 3
            ->send(2)
            ->through([
                $retryPipe,
            ])
            ->then(function ($value) {
                return $value;
            });

        $this->assertEquals(10, $result); // 2 * 5 = 10
    }
}
