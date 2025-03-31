<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use Mockery;
use RuntimeException;

class CircuitBreakerTest extends TestCase
{
    /**
     * Test that a failure in half-open state immediately re-opens the circuit.
     */
    public function test_failure_in_half_open_state_reopens_circuit()
    {
        // Create a mock inner strategy that always allows retries
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')
            ->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')
            ->andReturn(0);

        // Create a circuit breaker with a short reset timeout for testing
        $strategy = new CircuitBreakerStrategy(
            $innerStrategy,
            failureThreshold: 1, // Open after 1 failure
            resetTimeout: 1 // 1 second
        );

        // Create a test exception
        $exception = new RuntimeException('Test exception');

        // First failure should increment the failure count but still allow retry
        $this->assertTrue($strategy->shouldRetry(0, 3, $exception));

        // Second failure should open the circuit and not allow retry
        $this->assertFalse($strategy->shouldRetry(1, 3, $exception));

        // Verify circuit is open by checking that a retry is not allowed
        $this->assertFalse($strategy->shouldRetry(2, 3, null));

        // Wait for reset timeout to transition to half-open
        sleep(2);

        // Now circuit should be half-open (allowing a single attempt)
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // A failure in half-open state should re-open the circuit
        // Note: We need to pass the exception to indicate a failure
        $this->assertFalse($strategy->shouldRetry(2, 3, $exception));

        // Verify circuit is re-opened by checking that a retry is not allowed
        $this->assertFalse($strategy->shouldRetry(2, 3, null));

        // It should remain open even after a short wait
        usleep(100000); // 100ms
        $this->assertFalse($strategy->shouldRetry(2, 3, null));

        // But should transition to half-open again after reset timeout
        sleep(2);
        $this->assertTrue($strategy->shouldRetry(2, 3, null));
    }

    /**
     * Test that a success in half-open state closes the circuit.
     */
    public function test_success_in_half_open_state_closes_circuit()
    {
        // Create a mock inner strategy that always allows retries
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')
            ->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')
            ->andReturn(0);

        // Create a circuit breaker with a short reset timeout for testing
        $strategy = new CircuitBreakerStrategy(
            $innerStrategy,
            failureThreshold: 1, // Open after 1 failure
            resetTimeout: 1 // 1 second
        );

        // Create a test exception
        $exception = new RuntimeException('Test exception');

        // First failure should increment the failure count but still allow retry
        $this->assertTrue($strategy->shouldRetry(0, 3, $exception));

        // Second failure should open the circuit and not allow retry
        $this->assertFalse($strategy->shouldRetry(1, 3, $exception));

        // Verify circuit is open by checking that a retry is not allowed
        $this->assertFalse($strategy->shouldRetry(2, 3, null));

        // Wait for reset timeout to transition to half-open
        sleep(2);

        // Now circuit should be half-open (allowing a single attempt)
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // A success in half-open state should close the circuit
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // Verify circuit is closed by checking that multiple retries are allowed
        $this->assertTrue($strategy->shouldRetry(2, 3, null));
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // It should remain closed even after a successful reset timeout period
        sleep(2);
        $this->assertTrue($strategy->shouldRetry(2, 3, null));
    }

    /**
     * Test that multiple successes in half-open state keep the circuit closed.
     */
    public function test_multiple_successes_in_half_open_state()
    {
        // Create a mock inner strategy that always allows retries
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')
            ->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')
            ->andReturn(0);

        // Create a circuit breaker
        $strategy = new CircuitBreakerStrategy(
            $innerStrategy,
            failureThreshold: 1, // Open after 1 failure
            resetTimeout: 1 // 1 second
        );

        // Create a test exception
        $exception = new RuntimeException('Test exception');

        // First failure should increment the failure count but still allow retry
        $this->assertTrue($strategy->shouldRetry(0, 3, $exception));

        // Second failure should open the circuit and not allow retry
        $this->assertFalse($strategy->shouldRetry(1, 3, $exception));

        // Verify circuit is open by checking that a retry is not allowed
        $this->assertFalse($strategy->shouldRetry(2, 3, null));

        // Wait for reset timeout to transition to half-open
        sleep(2);

        // First attempt in half-open state should be allowed
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // A success in half-open state should close the circuit
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // Verify circuit is closed by checking that multiple retries are allowed
        $this->assertTrue($strategy->shouldRetry(2, 3, null));
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // And it should remain closed after a single failure (below threshold)
        $this->assertTrue($strategy->shouldRetry(2, 3, $exception));
    }

    /**
     * Test that a mix of successes and failures in half-open state
     * with specific success threshold requirements.
     */
    public function test_mix_of_success_and_failure_in_half_open_state()
    {
        // Create a mock inner strategy that always allows retries
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')
            ->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')
            ->andReturn(0);

        // Create a circuit breaker
        $strategy = new CircuitBreakerStrategy(
            $innerStrategy,
            failureThreshold: 1, // Open after 1 failure
            resetTimeout: 1 // 1 second
        );

        // Create a test exception
        $exception = new RuntimeException('Test exception');

        // First failure should increment the failure count but still allow retry
        $this->assertTrue($strategy->shouldRetry(0, 3, $exception));

        // Second failure should open the circuit and not allow retry
        $this->assertFalse($strategy->shouldRetry(1, 3, $exception));

        // Wait for reset timeout to transition to half-open
        sleep(2);

        // First attempt in half-open state should be allowed
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // A success in half-open state should close the circuit
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // Verifying we can make multiple attempts in closed state
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($strategy->shouldRetry(2, 3, null), "Attempt $i should be allowed");
        }
    }
}
