<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test that a failure in half-open state immediately re-opens the circuit.
     */
    public function test_failure_in_half_open_state_reopens_circuit()
    {
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')
            ->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')
            ->andReturn(0);

        $strategy = new CircuitBreakerStrategy(
            $innerStrategy,
            failureThreshold: 1,
            resetTimeout: 1
        );

        $exception = new RuntimeException('Test exception');

        // First failure should increment the failure count but still allow retry
        $this->assertTrue($strategy->shouldRetry(0, 3, $exception));
        $this->assertEquals('closed', $strategy->getCircuitState());

        // Second failure should open the circuit and not allow retry
        $this->assertFalse($strategy->shouldRetry(1, 3, $exception));
        $this->assertEquals('open', $strategy->getCircuitState());

        // Wait for reset timeout to transition to half-open
        $this->travel(2)->seconds();

        // Now circuit should be half-open (allowing a single attempt)
        $this->assertTrue($strategy->shouldRetry(2, 3, null));
        $this->assertEquals('half-open', $strategy->getCircuitState());

        // A failure in half-open state should re-open the circuit
        $this->assertFalse($strategy->shouldRetry(2, 3, $exception));
        $this->assertEquals('open', $strategy->getCircuitState());
    }

    /**
     * Test that a success in half-open state closes the circuit.
     */
    public function test_success_in_half_open_state_closes_circuit()
    {
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')
            ->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')
            ->andReturn(0);

        $strategy = new CircuitBreakerStrategy(
            $innerStrategy,
            failureThreshold: 1,
            resetTimeout: 1
        );

        $exception = new RuntimeException('Test exception');

        // First failure should increment the failure count but still allow retry
        $this->assertTrue($strategy->shouldRetry(0, 3, $exception));
        $this->assertEquals('closed', $strategy->getCircuitState());

        // Second failure should open the circuit and not allow retry
        $this->assertFalse($strategy->shouldRetry(1, 3, $exception));
        $this->assertEquals('open', $strategy->getCircuitState());

        // Wait for reset timeout to transition to half-open
        $this->travel(2)->seconds();

        // Now circuit should be half-open (allowing a single attempt)
        $this->assertTrue($strategy->shouldRetry(2, 3, null));
        $this->assertEquals('half-open', $strategy->getCircuitState());

        // A success in half-open state should close the circuit
        $this->assertTrue($strategy->shouldRetry(2, 3, null));
        $this->assertEquals('closed', $strategy->getCircuitState());
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
        $this->travel(2)->seconds();

        // First attempt in half-open state should be allowed
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // A success in half-open state should close the circuit
        $this->assertTrue($strategy->shouldRetry(2, 3, null));

        // Verify state immediately after processing the success
        $this->assertEquals('closed', $strategy->getCircuitState(), 'State check after success: Should be CLOSED');
        $this->assertEquals(0, $strategy->getFailureCount(), 'Failure count check after success: Should be 0');

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
        $this->travel(2)->seconds();

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
