<?php

namespace Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Strategies\FibonacciBackoffStrategy;
use PHPUnit\Framework\TestCase;

class FibonacciBackoffStrategyTest extends TestCase
{
    /** @test */
    public function it_calculates_correct_fibonacci_backoff_delays()
    {
        $strategy = new FibonacciBackoffStrategy();
        
        // Base delay of 1 second
        // The first few Fibonacci numbers are 1, 1, 2, 3, 5, 8, 13, 21, 34, 55
        $this->assertEquals(1, $strategy->getDelay(0, 1.0)); // 1 * 1
        $this->assertEquals(1, $strategy->getDelay(1, 1.0)); // 1 * 1
        $this->assertEquals(2, $strategy->getDelay(2, 1.0)); // 2 * 1
        $this->assertEquals(3, $strategy->getDelay(3, 1.0)); // 3 * 1
        $this->assertEquals(5, $strategy->getDelay(4, 1.0)); // 5 * 1
        $this->assertEquals(8, $strategy->getDelay(5, 1.0)); // 8 * 1
        
        // Base delay of 2 seconds
        $this->assertEquals(2, $strategy->getDelay(0, 2.0)); // 1 * 2
        $this->assertEquals(2, $strategy->getDelay(1, 2.0)); // 1 * 2
        $this->assertEquals(4, $strategy->getDelay(2, 2.0)); // 2 * 2
        $this->assertEquals(6, $strategy->getDelay(3, 2.0)); // 3 * 2
        $this->assertEquals(10, $strategy->getDelay(4, 2.0)); // 5 * 2
        $this->assertEquals(16, $strategy->getDelay(5, 2.0)); // 8 * 2
    }
    
    /** @test */
    public function it_respects_max_delay()
    {
        $strategy = new FibonacciBackoffStrategy(maxDelay: 10);
        
        // Base delay of 3 seconds
        $this->assertEquals(3, $strategy->getDelay(0, 3.0)); // 1 * 3 = 3, under max
        $this->assertEquals(3, $strategy->getDelay(1, 3.0)); // 1 * 3 = 3, under max
        $this->assertEquals(6, $strategy->getDelay(2, 3.0)); // 2 * 3 = 6, under max
        $this->assertEquals(9, $strategy->getDelay(3, 3.0)); // 3 * 3 = 9, under max
        $this->assertEquals(10, $strategy->getDelay(4, 3.0)); // 5 * 3 = 15, capped at 10
        $this->assertEquals(10, $strategy->getDelay(5, 3.0)); // 8 * 3 = 24, capped at 10
    }
    
    /** @test */
    public function it_adds_jitter_when_enabled()
    {
        $strategy = new FibonacciBackoffStrategy(withJitter: true);
        
        // Since jitter adds randomness, we'll test that the value stays within expected bounds
        $baseDelay = 10.0;
        
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $fibNumber = $this->getFibonacciNumber($attempt + 1);
            $expectedDelay = $fibNumber * $baseDelay;
            $minDelay = $expectedDelay * 0.8; // -20%
            $maxDelay = $expectedDelay * 1.2; // +20%
            
            $actualDelay = $strategy->getDelay($attempt, $baseDelay);
            
            $this->assertGreaterThanOrEqual(ceil($minDelay), $actualDelay);
            $this->assertLessThanOrEqual(ceil($maxDelay), $actualDelay);
        }
    }
    
    /** @test */
    public function it_determines_retry_based_on_max_attempts()
    {
        $strategy = new FibonacciBackoffStrategy();
        
        $this->assertTrue($strategy->shouldRetry(0, 3)); // attempt 0, max 3 -> should retry
        $this->assertTrue($strategy->shouldRetry(1, 3)); // attempt 1, max 3 -> should retry
        $this->assertTrue($strategy->shouldRetry(2, 3)); // attempt 2, max 3 -> should retry
        $this->assertFalse($strategy->shouldRetry(3, 3)); // attempt 3, max 3 -> should not retry
        $this->assertFalse($strategy->shouldRetry(4, 3)); // attempt 4, max 3 -> should not retry
    }
    
    /** @test */
    public function it_handles_very_large_attempt_numbers_safely()
    {
        $strategy = new FibonacciBackoffStrategy();
        
        // Attempting to calculate Fibonacci number larger than the safety limit (70)
        // Should not cause overflow but return a value up to PHP_INT_MAX
        $result = $strategy->getDelay(100, 1.0);
        
        // The safety check should ensure we get a positive integer that's less than or equal to PHP_INT_MAX
        $this->assertGreaterThan(0, $result);
        $this->assertLessThanOrEqual(PHP_INT_MAX, $result);
    }
    
    /**
     * Helper method to calculate the nth Fibonacci number for testing
     */
    private function getFibonacciNumber(int $n): int
    {
        if ($n <= 0) {
            return 0;
        }
        
        if ($n <= 2) {
            return 1;
        }
        
        $a = 1; // First Fibonacci number
        $b = 1; // Second Fibonacci number
        
        for ($i = 3; $i <= $n; $i++) {
            $c = $a + $b;
            $a = $b;
            $b = $c;
        }
        
        return $b;
    }
} 