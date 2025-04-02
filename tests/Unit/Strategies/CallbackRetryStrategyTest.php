<?php

namespace Tests\Unit\Strategies;

use Exception;
use GregPriday\LaravelRetry\Strategies\CallbackRetryStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CallbackRetryStrategyTest extends TestCase
{
    public function test_delay_callback_controls_delay()
    {
        $strategy = new CallbackRetryStrategy(
            delayCallback: fn ($attempt, $baseDelay) => $baseDelay * ($attempt + 1)
        );

        $this->assertEquals(1.0, $strategy->getDelay(0));
        $this->assertEquals(2.0, $strategy->getDelay(1));
        $this->assertEquals(3.0, $strategy->getDelay(2));
    }

    public function test_default_should_retry_uses_max_attempts()
    {
        $strategy = new CallbackRetryStrategy(
            delayCallback: fn () => 1.0
        );

        $this->assertTrue($strategy->shouldRetry(0, 3));
        $this->assertTrue($strategy->shouldRetry(1, 3));
        $this->assertTrue($strategy->shouldRetry(2, 3));
        $this->assertFalse($strategy->shouldRetry(3, 3));
    }

    public function test_custom_should_retry_callback()
    {
        $strategy = new CallbackRetryStrategy(
            delayCallback: fn () => 1.0,
            shouldRetryCallback: fn ($attempt, $maxAttempts, $exception) => $exception instanceof RuntimeException
        );

        $this->assertFalse($strategy->shouldRetry(0, 3)); // No exception
        $this->assertTrue($strategy->shouldRetry(0, 3, new RuntimeException('Test')));
        $this->assertFalse($strategy->shouldRetry(0, 3, new Exception('Other')));
    }

    public function test_options_passed_to_callbacks()
    {
        $strategy = new CallbackRetryStrategy(
            delayCallback: fn ($attempt, $baseDelay, $maxAttempts, $exception, $options) => $options['factor'] * $attempt,
            options: ['factor' => 2.0]
        );

        $this->assertEquals(0.0, $strategy->getDelay(0));
        $this->assertEquals(2.0, $strategy->getDelay(1));
        $this->assertEquals(4.0, $strategy->getDelay(2));
    }

    public function test_exception_passed_to_delay_callback()
    {
        $strategy = new CallbackRetryStrategy(
            delayCallback: fn ($attempt, $baseDelay, $maxAttempts, $exception) => $exception ? 5.0 : 1.0
        );

        $strategy->shouldRetry(0, 3, new RuntimeException('Test')); // Set exception
        $this->assertEquals(5.0, $strategy->getDelay(0));

        $strategy->shouldRetry(1, 3); // No exception
        $this->assertEquals(1.0, $strategy->getDelay(1));
    }

    public function test_negative_delay_is_clamped_to_zero()
    {
        $strategy = new CallbackRetryStrategy(
            delayCallback: fn () => -1.0
        );

        $this->assertEquals(0.0, $strategy->getDelay(0));
    }

    public function test_negative_base_delay_is_clamped()
    {
        $strategy = new CallbackRetryStrategy(
            delayCallback: fn ($attempt, $baseDelay) => $baseDelay,
            baseDelay: -2.0
        );

        $this->assertEquals(1.0, $strategy->getDelay(0)); // Uses default 1.0
    }

    public function test_max_attempts_passed_to_delay_callback()
    {
        $strategy = new CallbackRetryStrategy(
            delayCallback: fn ($attempt, $baseDelay, $maxAttempts) => $maxAttempts - $attempt
        );

        $strategy->shouldRetry(0, 3); // Set maxAttempts
        $this->assertEquals(3.0, $strategy->getDelay(0)); // 3 - 0
        $this->assertEquals(2.0, $strategy->getDelay(1)); // 3 - 1
        $this->assertEquals(1.0, $strategy->getDelay(2)); // 3 - 2
    }
}
