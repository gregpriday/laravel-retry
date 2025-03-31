<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;

class RateLimitStrategyTest extends TestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear rate limiter before each test
        RateLimiter::clear('test_rate_limit_persistence');
        RateLimiter::clear('key1');
        RateLimiter::clear('key2');
        RateLimiter::clear('test_expiration');
        RateLimiter::clear('test_zero');
        RateLimiter::clear('test_large');
    }

    /**
     * Test rate limit persistence across multiple operations with shared storage.
     */
    public function test_rate_limit_persistence_across_operations()
    {
        // Create a mock inner strategy
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')->andReturn(0);

        // Create a rate limiter with shared storage key and low limit for testing
        $storageKey = 'test_rate_limit_persistence';
        $maxAttempts = 3;
        $timeWindow = 10; // seconds

        // First instance of rate limiter
        $limiter1 = new RateLimitStrategy(
            $innerStrategy,
            maxAttempts: $maxAttempts,
            timeWindow: $timeWindow,
            storageKey: $storageKey
        );

        // Record some attempts
        $this->assertTrue($limiter1->shouldRetry(0, 5, null)); // 1st attempt
        $this->assertTrue($limiter1->shouldRetry(0, 5, null)); // 2nd attempt

        // Create a second independent instance with the same storage key
        $limiter2 = new RateLimitStrategy(
            $innerStrategy,
            maxAttempts: $maxAttempts,
            timeWindow: $timeWindow,
            storageKey: $storageKey
        );

        // The second limiter should see attempts from the first limiter
        $this->assertTrue($limiter2->shouldRetry(0, 5, null)); // 3rd attempt (across instances)

        // Fourth attempt should be denied on either instance
        $this->assertFalse($limiter1->shouldRetry(0, 5, null));
        $this->assertFalse($limiter2->shouldRetry(0, 5, null));

        // Clean up
        RateLimiter::clear($storageKey);
    }

    /**
     * Test rate limiters with different storage keys don't affect each other.
     */
    public function test_rate_limit_isolation_with_different_keys()
    {
        // Create a mock inner strategy
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')->andReturn(0);

        $maxAttempts = 2;
        $timeWindow = 10; // seconds

        // First limiter with key1
        $limiter1 = new RateLimitStrategy(
            $innerStrategy,
            maxAttempts: $maxAttempts,
            timeWindow: $timeWindow,
            storageKey: 'key1'
        );

        // Second limiter with key2
        $limiter2 = new RateLimitStrategy(
            $innerStrategy,
            maxAttempts: $maxAttempts,
            timeWindow: $timeWindow,
            storageKey: 'key2'
        );

        // Use up limiter1's quota
        $this->assertTrue($limiter1->shouldRetry(0, 5, null));
        $this->assertTrue($limiter1->shouldRetry(0, 5, null));

        // Limiter1 should now be limited
        $this->assertFalse($limiter1->shouldRetry(0, 5, null));

        // But limiter2 should still allow attempts
        $this->assertTrue($limiter2->shouldRetry(0, 5, null));
        $this->assertTrue($limiter2->shouldRetry(0, 5, null));

        // Now limiter2 should also be limited
        $this->assertFalse($limiter2->shouldRetry(0, 5, null));

        // Clean up
        RateLimiter::clear('key1');
        RateLimiter::clear('key2');
    }

    /**
     * Test that expiration of the time window resets the rate limit.
     */
    public function test_rate_limit_expiration()
    {
        // Create a mock inner strategy
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')->andReturn(0);

        // Create a rate limiter with a very short time window for testing
        $limiter = new RateLimitStrategy(
            $innerStrategy,
            maxAttempts: 2,
            timeWindow: 1, // 1 second window
            storageKey: 'test_expiration'
        );

        // Use up the quota
        $this->assertTrue($limiter->shouldRetry(0, 5, null));
        $this->assertTrue($limiter->shouldRetry(0, 5, null));

        // Verify we're limited
        $this->assertFalse($limiter->shouldRetry(0, 5, null));

        // Wait for the time window to expire
        sleep(2);

        // Should be able to make attempts again
        $this->assertTrue($limiter->shouldRetry(0, 5, null));
        $this->assertTrue($limiter->shouldRetry(0, 5, null));

        // And limited again after the quota is used
        $this->assertFalse($limiter->shouldRetry(0, 5, null));

        // Clean up
        RateLimiter::clear('test_expiration');
    }

    /**
     * Test edge case: zero max attempts (should never allow attempts).
     */
    public function test_zero_max_attempts()
    {
        // Create a mock inner strategy
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')->andReturn(0);

        $limiter = new RateLimitStrategy(
            $innerStrategy,
            maxAttempts: 0,
            timeWindow: 10,
            storageKey: 'test_zero'
        );

        // Should never allow attempts
        $this->assertFalse($limiter->shouldRetry(0, 5, null));
        $this->assertFalse($limiter->shouldRetry(0, 5, null));

        // Clean up
        RateLimiter::clear('test_zero');
    }

    /**
     * Test edge case: large max attempts value.
     */
    public function test_large_max_attempts()
    {
        // Create a mock inner strategy
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')->andReturn(0);

        $limiter = new RateLimitStrategy(
            $innerStrategy,
            maxAttempts: PHP_INT_MAX, // Very large value
            timeWindow: 10,
            storageKey: 'test_large'
        );

        // Should always allow attempts
        $this->assertTrue($limiter->shouldRetry(0, 5, null));

        // Even after recording an attempt
        $this->assertTrue($limiter->shouldRetry(0, 5, null));

        // Clean up
        RateLimiter::clear('test_large');
    }
    
    /**
     * Test the rate limit info method.
     */
    public function test_rate_limit_info()
    {
        // Create a mock inner strategy
        $innerStrategy = Mockery::mock(RetryStrategy::class);
        $innerStrategy->shouldReceive('shouldRetry')->andReturn(true);
        $innerStrategy->shouldReceive('getDelay')->andReturn(0);
        
        $storageKey = 'test_rate_limit_info';
        $maxAttempts = 5;
        $timeWindow = 60;
        
        $limiter = new RateLimitStrategy(
            $innerStrategy,
            maxAttempts: $maxAttempts,
            timeWindow: $timeWindow,
            storageKey: $storageKey
        );
        
        // Make some attempts
        $limiter->shouldRetry(0, 5, null);
        $limiter->shouldRetry(0, 5, null);
        
        // Check the info
        $info = $limiter->getRateLimitInfo();
        
        $this->assertEquals($maxAttempts, $info['max_attempts']);
        $this->assertEquals($timeWindow, $info['time_window']);
        $this->assertEquals($storageKey, $info['storage_key']);
        $this->assertEquals(3, $info['remaining']); // 5 max - 2 attempts = 3 remaining
        $this->assertEquals(2, $info['current_rate']); // 2 attempts made
        
        // Clean up
        RateLimiter::clear($storageKey);
    }
}
