<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\FibonacciBackoffStrategy;
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
            baseDelay: PHP_INT_MAX / 4,
            multiplier: 2.0,
            maxDelay: PHP_INT_MAX
        );

        // First attempt should use baseDelay
        $delay = $strategy->getDelay(0);
        $this->assertEquals(ceil(PHP_INT_MAX / 4), $delay);

        // Second attempt would be baseDelay * multiplier^attempt
        // which is (PHP_INT_MAX / 4) * 2^1 = PHP_INT_MAX / 2
        $delay = $strategy->getDelay(1);
        $this->assertEquals(ceil(PHP_INT_MAX / 2), $delay);
    }

    /**
     * Test zero or negative base delay with ExponentialBackoff.
     */
    public function test_exponential_backoff_with_zero_or_negative_base_delay()
    {
        // Zero base delay
        $zeroStrategy = new ExponentialBackoffStrategy(
            baseDelay: 0.0,
            multiplier: 2.0,
            maxDelay: 1000
        );

        // All delays should be 0 with zero base delay
        $this->assertEquals(0.0, $zeroStrategy->getDelay(0));
        $this->assertEquals(0.0, $zeroStrategy->getDelay(1));
        $this->assertEquals(0.0, $zeroStrategy->getDelay(2));

        // Negative base delay should be handled safely (return 0)
        $negativeStrategy = new ExponentialBackoffStrategy(
            baseDelay: -100.0,
            multiplier: 2.0,
            maxDelay: 1000
        );
        $delay = $negativeStrategy->getDelay(0);
        $this->assertEquals(0.0, $delay);
    }

    /**
     * Test linear backoff with negative or zero values.
     */
    public function test_linear_backoff_with_zero_or_negative_values()
    {
        // Zero base delay
        $zeroStrategy = new LinearBackoffStrategy(
            baseDelay: 0.0,
            increment: 100,
            maxDelay: 1000
        );

        // Delays should increase linearly from 0
        $this->assertEquals(0.0, $zeroStrategy->getDelay(0));
        $this->assertEquals(100.0, $zeroStrategy->getDelay(1));
        $this->assertEquals(200.0, $zeroStrategy->getDelay(2));

        // Negative base delay should be handled safely (return max(0, calculated value))
        $negativeStrategy = new LinearBackoffStrategy(
            baseDelay: -100.0,
            increment: 100,
            maxDelay: 1000
        );
        $this->assertEquals(0.0, $negativeStrategy->getDelay(0));

        // With enough increments, it will eventually become positive
        $this->assertEquals(0.0, $negativeStrategy->getDelay(1));
        $this->assertEquals(100.0, $negativeStrategy->getDelay(2));

        // Negative increment
        $negIncStrategy = new LinearBackoffStrategy(
            baseDelay: 100.0,
            increment: -50,
            maxDelay: 1000
        );

        // Base delay will decrease with each attempt but never go below 0
        $this->assertEquals(100.0, $negIncStrategy->getDelay(0));
        $this->assertEquals(50.0, $negIncStrategy->getDelay(1));
        $this->assertEquals(0.0, $negIncStrategy->getDelay(2));
    }

    /**
     * Test fixed delay with various edge cases.
     */
    public function test_fixed_delay_edge_cases()
    {
        // Zero delay
        $zeroStrategy = new FixedDelayStrategy(
            baseDelay: 0.0,
            withJitter: false
        );
        $this->assertEquals(0.0, $zeroStrategy->getDelay(0));
        $this->assertEquals(0.0, $zeroStrategy->getDelay(9));

        // Negative delay
        $negativeStrategy = new FixedDelayStrategy(
            baseDelay: -100.0,
            withJitter: false
        );
        $this->assertEquals(-100.0, $negativeStrategy->getDelay(0));
        $this->assertEquals(-100.0, $negativeStrategy->getDelay(9));

        // Large delay (but not PHP_INT_MAX to avoid potential overflow issues)
        $largeStrategy = new FixedDelayStrategy(
            baseDelay: 1000000.0,
            withJitter: false
        );
        $this->assertEquals(1000000.0, $largeStrategy->getDelay(0));
        $this->assertEquals(1000000.0, $largeStrategy->getDelay(9));
    }

    /**
     * Test decorrelated jitter with edge case values.
     */
    public function test_decorrelated_jitter_edge_cases()
    {
        // Zero base delay
        $zeroStrategy = new DecorrelatedJitterStrategy(
            baseDelay: 0.0,
            maxDelay: 1000,
            minFactor: 1.0,
            maxFactor: 3.0
        );

        // All delays should be 0 with zero base delay
        $this->assertEquals(0.0, $zeroStrategy->getDelay(0));
        $this->assertEquals(0.0, $zeroStrategy->getDelay(1));

        // Test with a reasonable base delay and equal min/max factors
        $equalFactorStrategy = new DecorrelatedJitterStrategy(
            baseDelay: 1000.0,
            maxDelay: 2000,
            minFactor: 1.0,
            maxFactor: 1.0
        );

        // Delay should be equal to baseDelay with minFactor=maxFactor=1.0
        $delay = $equalFactorStrategy->getDelay(0);
        $this->assertEquals(1000.0, $delay);
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

    /**
     * Test jitter with edge case percentages.
     */
    public function test_jitter_with_edge_case_percentages()
    {
        $baseDelay = 100.0;

        // Test with zero jitter percentage
        $zeroJitterExp = new ExponentialBackoffStrategy(
            baseDelay: $baseDelay,
            withJitter: true,
            jitterPercent: 0.0
        );
        $zeroJitterFib = new FibonacciBackoffStrategy(
            baseDelay: $baseDelay,
            withJitter: true,
            jitterPercent: 0.0
        );
        $zeroJitterFixed = new FixedDelayStrategy(
            baseDelay: $baseDelay,
            withJitter: true,
            jitterPercent: 0.0
        );

        // With 0% jitter, the delay should exactly match the calculated value
        $this->assertEquals($baseDelay, $zeroJitterExp->getDelay(0));
        $this->assertEquals($baseDelay, $zeroJitterFib->getDelay(0));
        $this->assertEquals($baseDelay, $zeroJitterFixed->getDelay(0));

        // Test with very high jitter percentage
        $highJitterExp = new ExponentialBackoffStrategy(
            baseDelay: $baseDelay,
            withJitter: true,
            jitterPercent: 1.0
        );
        $highJitterFib = new FibonacciBackoffStrategy(
            baseDelay: $baseDelay,
            withJitter: true,
            jitterPercent: 1.0
        );
        $highJitterFixed = new FixedDelayStrategy(
            baseDelay: $baseDelay,
            withJitter: true,
            jitterPercent: 1.0
        );

        // With 100% jitter, the delay should be between 0 and 2*baseDelay
        $delayExp = $highJitterExp->getDelay(0);
        $delayFib = $highJitterFib->getDelay(0);
        $delayFixed = $highJitterFixed->getDelay(0);

        $this->assertGreaterThanOrEqual(0, $delayExp);
        $this->assertLessThanOrEqual(2 * $baseDelay, $delayExp);

        $this->assertGreaterThanOrEqual(0, $delayFib);
        $this->assertLessThanOrEqual(2 * $baseDelay, $delayFib);

        $this->assertGreaterThanOrEqual(0, $delayFixed);
        $this->assertLessThanOrEqual(2 * $baseDelay, $delayFixed);

        // Test with withJitter=false but jitterPercent set
        $noJitterExp = new ExponentialBackoffStrategy(
            baseDelay: $baseDelay,
            withJitter: false,
            jitterPercent: 0.5
        );
        $noJitterFib = new FibonacciBackoffStrategy(
            baseDelay: $baseDelay,
            withJitter: false,
            jitterPercent: 0.5
        );
        $noJitterFixed = new FixedDelayStrategy(
            baseDelay: $baseDelay,
            withJitter: false,
            jitterPercent: 0.5
        );

        // Even with jitterPercent set, if withJitter=false, no jitter should be applied
        $this->assertEquals($baseDelay, $noJitterExp->getDelay(0));
        $this->assertEquals($baseDelay, $noJitterFib->getDelay(0));
        $this->assertEquals($baseDelay, $noJitterFixed->getDelay(0));
    }
}
