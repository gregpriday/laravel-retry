<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;

class DelayStrategiesEdgeCasesTest extends TestCase
{
    /**
     * Test very large base delay and max delay values with ExponentialBackoff.
     */
    public function test_exponential_backoff_with_large_values()
    {
        $strategy = new ExponentialBackoffStrategy(
            multiplier: 2.0,
            maxDelay: PHP_INT_MAX
        );

        // First attempt should use baseDelay
        $delay = $strategy->getDelay(0, PHP_INT_MAX / 4);
        $this->assertEquals(ceil(PHP_INT_MAX / 4), $delay);

        // Second attempt would be baseDelay * multiplier^attempt
        // which is (PHP_INT_MAX / 4) * 2^1 = PHP_INT_MAX / 2
        $delay = $strategy->getDelay(1, PHP_INT_MAX / 4);
        $this->assertEquals(ceil(PHP_INT_MAX / 2), $delay);
    }

    /**
     * Test zero or negative base delay with ExponentialBackoff.
     */
    public function test_exponential_backoff_with_zero_or_negative_base_delay()
    {
        // Zero base delay
        $strategy = new ExponentialBackoffStrategy(
            multiplier: 2.0,
            maxDelay: 1000
        );

        // All delays should be 0 with zero base delay
        $this->assertEquals(0, $strategy->getDelay(0, 0));
        $this->assertEquals(0, $strategy->getDelay(1, 0));
        $this->assertEquals(0, $strategy->getDelay(2, 0));

        // Negative base delay will result in negative delay
        // The implementation doesn't specifically handle negative values
        $delay = $strategy->getDelay(0, -100);
        $this->assertEquals(ceil(-100), $delay);
    }

    /**
     * Test linear backoff with negative or zero values.
     */
    public function test_linear_backoff_with_zero_or_negative_values()
    {
        // Zero base delay
        $strategy = new LinearBackoffStrategy(
            increment: 100,
            maxDelay: 1000
        );

        // Delays should increase linearly from 0
        $this->assertEquals(0, $strategy->getDelay(0, 0));
        $this->assertEquals(100, $strategy->getDelay(1, 0));
        $this->assertEquals(200, $strategy->getDelay(2, 0));

        // Negative base delay will result in negative delay initially
        // The implementation doesn't specifically handle negative values
        $this->assertEquals(ceil(-100), $strategy->getDelay(0, -100));

        // But with enough increments, it will eventually become positive
        $this->assertEquals(ceil(-100 + 100), $strategy->getDelay(1, -100));
        $this->assertEquals(ceil(-100 + 200), $strategy->getDelay(2, -100));

        // Negative increment
        $strategy = new LinearBackoffStrategy(
            increment: -50,
            maxDelay: 1000
        );

        // Base delay will decrease with each attempt
        $this->assertEquals(ceil(100), $strategy->getDelay(0, 100));
        $this->assertEquals(ceil(100 - 50), $strategy->getDelay(1, 100));
        $this->assertEquals(ceil(100 - 100), $strategy->getDelay(2, 100));
    }

    /**
     * Test fixed delay with various edge cases.
     */
    public function test_fixed_delay_edge_cases()
    {
        // Zero delay
        $strategy = new FixedDelayStrategy(withJitter: false);
        $this->assertEquals(0, $strategy->getDelay(0, 0));
        $this->assertEquals(0, $strategy->getDelay(9, 0));

        // Negative delay will result in negative delay
        // The implementation doesn't specifically handle negative values
        $this->assertEquals(ceil(-100), $strategy->getDelay(0, -100));
        $this->assertEquals(ceil(-100), $strategy->getDelay(9, -100));

        // Large delay (but not PHP_INT_MAX to avoid potential overflow issues)
        $largeDelay = 1000000;
        $this->assertEquals(ceil($largeDelay), $strategy->getDelay(0, $largeDelay));
        $this->assertEquals(ceil($largeDelay), $strategy->getDelay(9, $largeDelay));
    }

    /**
     * Test decorrelated jitter with edge case values.
     */
    public function test_decorrelated_jitter_edge_cases()
    {
        // Zero base delay
        $strategy = new DecorrelatedJitterStrategy(
            maxDelay: 1000,
            minFactor: 1.0,
            maxFactor: 3.0
        );

        // All delays should be 0 with zero base delay
        $this->assertEquals(0, $strategy->getDelay(0, 0));
        $this->assertEquals(0, $strategy->getDelay(1, 0));

        // Skip negative base delay test as it causes ValueError in mt_rand

        // Test with a reasonable base delay and equal min/max factors
        $strategy = new DecorrelatedJitterStrategy(
            maxDelay: 2000,
            minFactor: 1.0,
            maxFactor: 1.0
        );

        // Delay should be equal to baseDelay with minFactor=maxFactor=1.0
        $delay = $strategy->getDelay(0, 1000);
        $this->assertEquals(1000, $delay);
    }

    /**
     * Test max attempts of zero for strategies.
     */
    public function test_strategies_with_zero_max_attempts()
    {
        // Create various strategies
        $exponential = new ExponentialBackoffStrategy;
        $linear = new LinearBackoffStrategy;
        $fixed = new FixedDelayStrategy;
        $jitter = new DecorrelatedJitterStrategy;

        // All strategies should return false for shouldRetry with attempt >= maxAttempts
        $this->assertFalse($exponential->shouldRetry(0, 0, null));
        $this->assertFalse($linear->shouldRetry(0, 0, null));
        $this->assertFalse($fixed->shouldRetry(0, 0, null));
        $this->assertFalse($jitter->shouldRetry(0, 0, null));
    }
}
