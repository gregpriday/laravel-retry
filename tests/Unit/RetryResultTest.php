<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Exception;
use GregPriday\LaravelRetry\RetryResult;
use GregPriday\LaravelRetry\Tests\TestCase;
use RuntimeException;
use Throwable;

class RetryResultTest extends TestCase
{
    public function test_successful_result_handling(): void
    {
        $result = new RetryResult('success');

        $this->assertEquals('success', $result->value());
        $this->assertEquals('success', $result->getResult());
        $this->assertNull($result->getError());
        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->failed());
        $this->assertEmpty($result->getExceptionHistory());
    }

    public function test_error_handling(): void
    {
        $error = new Exception('Test error');
        $result = new RetryResult(null, $error);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Test error');

        $this->assertNull($result->getResult());
        $this->assertSame($error, $result->getError());
        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->failed());

        $result->value(); // Should throw
    }

    public function test_then_callback_on_success(): void
    {
        $result = (new RetryResult('initial'))
            ->then(fn ($value) => $value.' success')
            ->then(fn ($value) => $value.' chain');

        $this->assertEquals('initial success chain', $result->value());
        $this->assertTrue($result->succeeded());
    }

    public function test_then_callback_is_skipped_on_error(): void
    {
        $error = new Exception('Test error');
        $result = (new RetryResult(null, $error))
            ->then(fn ($value) => 'should not be called');

        $this->assertSame($error, $result->getError());
        $this->assertTrue($result->failed());
    }

    public function test_catch_callback_on_error(): void
    {
        $result = (new RetryResult(null, new Exception('Original error')))
            ->catch(fn (Throwable $e) => 'recovered');

        $this->assertEquals('recovered', $result->value());
        $this->assertTrue($result->succeeded());
    }

    public function test_catch_callback_is_skipped_on_success(): void
    {
        $result = (new RetryResult('success'))
            ->catch(fn (Throwable $e) => 'should not be called');

        $this->assertEquals('success', $result->value());
        $this->assertTrue($result->succeeded());
    }

    public function test_finally_callback_is_always_called(): void
    {
        $called = false;
        $finallyCallback = function () use (&$called) {
            $called = true;
        };

        // Test with success
        $successResult = (new RetryResult('success'))
            ->finally($finallyCallback);

        $this->assertTrue($called);
        $this->assertEquals('success', $successResult->value());

        // Reset and test with error
        $called = false;
        $errorResult = (new RetryResult(null, new Exception('error')))
            ->finally($finallyCallback);

        $this->assertTrue($called);
        $this->assertTrue($errorResult->failed());
    }

    public function test_exception_history_tracking(): void
    {
        $history = [
            [
                'attempt'       => 0,
                'exception'     => new RuntimeException('First error'),
                'timestamp'     => time(),
                'was_retryable' => true,
            ],
            [
                'attempt'       => 1,
                'exception'     => new RuntimeException('Second error'),
                'timestamp'     => time(),
                'was_retryable' => true,
            ],
        ];

        $result = new RetryResult('success', null, $history);

        $this->assertEquals($history, $result->getExceptionHistory());
    }

    public function test_throw_and_throw_first_methods(): void
    {
        $history = [
            [
                'attempt'       => 0,
                'exception'     => new RuntimeException('First error'),
                'timestamp'     => time(),
                'was_retryable' => true,
            ],
            [
                'attempt'       => 1,
                'exception'     => new RuntimeException('Second error'),
                'timestamp'     => time(),
                'was_retryable' => true,
            ],
        ];

        $finalError = new RuntimeException('Final error');
        $result = new RetryResult(null, $finalError, $history);

        // Test throw() throws the final error
        try {
            $result->throw();
            $this->fail('throw() should have thrown an exception');
        } catch (RuntimeException $e) {
            $this->assertEquals('Final error', $e->getMessage());
        }

        // Test throwFirst() throws the first error from history
        try {
            $result->throwFirst();
            $this->fail('throwFirst() should have thrown an exception');
        } catch (RuntimeException $e) {
            $this->assertEquals('First error', $e->getMessage());
        }
    }

    public function test_throw_methods_do_nothing_on_success(): void
    {
        $result = new RetryResult('success');

        // These should not throw
        $result->throw();
        $result->throwFirst();

        $this->assertTrue($result->succeeded());
    }

    public function test_error_in_then_callback(): void
    {
        $result = (new RetryResult('success'))
            ->then(function ($value) {
                throw new RuntimeException('Callback error');
            });

        $this->assertTrue($result->failed());
        $this->assertInstanceOf(RuntimeException::class, $result->getError());
        $this->assertEquals('Callback error', $result->getError()->getMessage());
    }

    public function test_error_in_catch_callback(): void
    {
        $result = (new RetryResult(null, new Exception('Original error')))
            ->catch(function ($error) {
                throw new RuntimeException('Catch error');
            });

        $this->assertTrue($result->failed());
        $this->assertInstanceOf(RuntimeException::class, $result->getError());
        $this->assertEquals('Catch error', $result->getError()->getMessage());
    }

    public function test_error_in_finally_callback(): void
    {
        $result = (new RetryResult('success'))
            ->finally(function () {
                throw new RuntimeException('Finally error');
            });

        $this->assertTrue($result->failed());
        $this->assertInstanceOf(RuntimeException::class, $result->getError());
        $this->assertEquals('Finally error', $result->getError()->getMessage());
    }
}
