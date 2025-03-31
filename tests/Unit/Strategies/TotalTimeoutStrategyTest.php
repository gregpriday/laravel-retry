<?php

namespace Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\TotalTimeoutStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TotalTimeoutStrategyTest extends TestCase
{
    /** @test */
    public function it_delegates_delay_calculation_to_inner_strategy()
    {
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->once())
            ->method('getDelay')
            ->with(2, 5.0)
            ->willReturn(10);
            
        $strategy = new TotalTimeoutStrategy($innerStrategy, 60);
        
        $this->assertEquals(10, $strategy->getDelay(2, 5.0));
    }
    
    /** @test */
    public function it_caps_delay_when_approaching_total_timeout()
    {
        $innerStrategy = new ExponentialBackoffStrategy();
        $strategy = new TotalTimeoutStrategy($innerStrategy, 10);
        
        // Simulate that 7 seconds have already elapsed
        $reflection = new \ReflectionProperty($strategy, 'startTime');
        $reflection->setAccessible(true);
        $reflection->setValue($strategy, microtime(true) - 7);
        
        // Inner strategy would return 8 seconds for attempt 3, but we have only 3 seconds left
        // So we should get a value <= 3
        $delay = $strategy->getDelay(3, 1.0);
        $this->assertLessThanOrEqual(3, $delay);
    }
    
    /** @test */
    public function it_returns_zero_delay_when_timeout_reached()
    {
        $innerStrategy = new ExponentialBackoffStrategy();
        $strategy = new TotalTimeoutStrategy($innerStrategy, 5);
        
        // Simulate that 6 seconds have already elapsed (exceeding timeout)
        $reflection = new \ReflectionProperty($strategy, 'startTime');
        $reflection->setAccessible(true);
        $reflection->setValue($strategy, microtime(true) - 6);
        
        // Should return 0 since we've already exceeded the timeout
        $this->assertEquals(0, $strategy->getDelay(1, 2.0));
    }
    
    /** @test */
    public function it_delegates_retry_decision_to_inner_strategy_when_within_timeout()
    {
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->once())
            ->method('shouldRetry')
            ->with(2, 5, null)
            ->willReturn(true);
            
        $strategy = new TotalTimeoutStrategy($innerStrategy, 60);
        
        $this->assertTrue($strategy->shouldRetry(2, 5));
    }
    
    /** @test */
    public function it_prevents_retry_when_total_timeout_exceeded()
    {
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->never())->method('shouldRetry');
            
        $strategy = new TotalTimeoutStrategy($innerStrategy, 5);
        
        // Simulate that 6 seconds have already elapsed (exceeding timeout)
        $reflection = new \ReflectionProperty($strategy, 'startTime');
        $reflection->setAccessible(true);
        $reflection->setValue($strategy, microtime(true) - 6);
        
        $this->assertFalse($strategy->shouldRetry(2, 5));
    }
    
    /** @test */
    public function it_respects_inner_strategy_retry_decision_when_within_timeout()
    {
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->method('shouldRetry')->willReturn(false);
            
        $strategy = new TotalTimeoutStrategy($innerStrategy, 60);
        
        // Inner strategy says no, even though we're within the total timeout
        $this->assertFalse($strategy->shouldRetry(2, 5));
    }
    
    /** @test */
    public function it_can_reset_start_time()
    {
        $innerStrategy = new ExponentialBackoffStrategy();
        $strategy = new TotalTimeoutStrategy($innerStrategy, 10);
        
        // Simulate that 7 seconds have already elapsed
        $startTimeReflection = new \ReflectionProperty($strategy, 'startTime');
        $startTimeReflection->setAccessible(true);
        $oldStartTime = microtime(true) - 7;
        $startTimeReflection->setValue($strategy, $oldStartTime);
        
        // Before reset, elapsed time should be around 7 seconds
        $elapsedTime = $strategy->getElapsedTime();
        $this->assertGreaterThanOrEqual(6.9, $elapsedTime);
        $this->assertLessThanOrEqual(7.1, $elapsedTime);
        
        // Reset the start time
        $strategy->resetStartTime();
        
        // After reset, elapsed time should be close to 0
        $newElapsedTime = $strategy->getElapsedTime();
        $this->assertLessThanOrEqual(0.1, $newElapsedTime);
        
        // The start time should be different after reset
        $this->assertNotEquals($oldStartTime, $startTimeReflection->getValue($strategy));
    }
    
    /** @test */
    public function it_provides_access_to_inner_strategy()
    {
        $innerStrategy = new ExponentialBackoffStrategy();
        $strategy = new TotalTimeoutStrategy($innerStrategy, 60);
        
        $this->assertSame($innerStrategy, $strategy->getInnerStrategy());
    }
    
    /** @test */
    public function it_provides_access_to_total_timeout()
    {
        $innerStrategy = new ExponentialBackoffStrategy();
        $strategy = new TotalTimeoutStrategy($innerStrategy, 60);
        
        $this->assertEquals(60, $strategy->getTotalTimeout());
    }
} 