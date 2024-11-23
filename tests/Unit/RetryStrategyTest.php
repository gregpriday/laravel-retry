<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use Generator;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use RuntimeException;

class RetryStrategyTest extends TestCase
{
    /**
     * Data provider for strategy tests
     */
    public function strategyProvider(): Generator
    {
        yield 'exponential backoff' => [
            'strategy'             => new ExponentialBackoffStrategy(multiplier: 2.0, maxDelay: 30),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay): array {
                $delay = $baseDelay * pow(2, $attempt);

                return [
                    'min' => (int) min($delay, 30),
                    'max' => (int) min($delay, 30),
                ];
            },
        ];

        yield 'linear backoff' => [
            'strategy'             => new LinearBackoffStrategy(increment: 5, maxDelay: 30),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                $delay = $baseDelay + (5 * $attempt);

                return [
                    'min' => (int) min($delay, 30),
                    'max' => (int) min($delay, 30),
                ];
            },
        ];

        yield 'fixed delay' => [
            'strategy'             => new FixedDelayStrategy,
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) $baseDelay,
                    'max' => (int) $baseDelay,
                ];
            },
        ];

        yield 'fixed delay with jitter' => [
            'strategy'             => new FixedDelayStrategy(withJitter: true, jitterPercent: 0.2),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) ($baseDelay * 0.8),
                    'max' => (int) ($baseDelay * 1.2),
                ];
            },
        ];

        yield 'decorrelated jitter' => [
            'strategy'             => new DecorrelatedJitterStrategy(maxDelay: 30),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) $baseDelay,
                    'max' => (int) min(30, $baseDelay * 3 * (1 << $attempt)),
                ];
            },
        ];

        yield 'rate limit' => [
            'strategy' => new RateLimitStrategy(
                innerStrategy: new FixedDelayStrategy,
                maxAttempts: 5,
                timeWindow: 10
            ),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) $baseDelay,
                    'max' => PHP_INT_MAX, // Rate limit might add additional delay
                ];
            },
        ];

        yield 'circuit breaker' => [
            'strategy' => new CircuitBreakerStrategy(
                innerStrategy: new FixedDelayStrategy,
                failureThreshold: 3,
                resetTimeout: 5
            ),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) $baseDelay,
                    'max' => (int) $baseDelay,
                ];
            },
        ];
    }

    /**
     * @dataProvider strategyProvider
     */
    public function test_strategy_delay_patterns(
        RetryStrategy $strategy,
        callable $expectedDelayPattern
    ): void {
        $baseDelay = 5;

        // Test delays for first few attempts
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $delay = $strategy->getDelay($attempt, $baseDelay);
            $expected = $expectedDelayPattern($attempt, $baseDelay);

            $this->assertGreaterThanOrEqual(
                $expected['min'],
                $delay,
                "Attempt {$attempt}: Delay should be >= {$expected['min']}"
            );

            $this->assertLessThanOrEqual(
                $expected['max'],
                $delay,
                "Attempt {$attempt}: Delay should be <= {$expected['max']}"
            );
        }
    }

    /**
     * @dataProvider strategyProvider
     */
    public function test_strategy_respects_max_attempts(RetryStrategy $strategy): void
    {
        $maxAttempts = 3;

        for ($attempt = 0; $attempt <= $maxAttempts + 1; $attempt++) {
            $shouldRetry = $strategy->shouldRetry($attempt, $maxAttempts, null);

            if ($attempt < $maxAttempts) {
                $this->assertTrue(
                    $shouldRetry,
                    "Strategy should allow retry for attempt {$attempt} when under max attempts"
                );
            } else {
                $this->assertFalse(
                    $shouldRetry,
                    "Strategy should not allow retry for attempt {$attempt} when at/over max attempts"
                );
            }
        }
    }

    /**
     * @dataProvider strategyProvider
     */
    public function test_strategy_handles_exceptions(RetryStrategy $strategy): void
    {
        $exception = new RuntimeException('Test exception');
        $maxAttempts = 3;

        // Strategy should allow retries with exception if under max attempts
        $this->assertTrue(
            $strategy->shouldRetry(0, $maxAttempts, $exception),
            'Strategy should allow retry with exception under max attempts'
        );

        // Strategy should not allow retries with exception if at/over max attempts
        $this->assertFalse(
            $strategy->shouldRetry($maxAttempts, $maxAttempts, $exception),
            'Strategy should not allow retry with exception at max attempts'
        );
    }

    public function test_rate_limit_strategy_specific_behavior(): void
    {
        $strategy = new RateLimitStrategy(
            innerStrategy: new FixedDelayStrategy,
            maxAttempts: 3,
            timeWindow: 1
        );

        // Should allow initial attempts
        $this->assertTrue($strategy->shouldRetry(0, 5, null));
        $this->assertTrue($strategy->shouldRetry(1, 5, null));
        $this->assertTrue($strategy->shouldRetry(2, 5, null));

        // Should deny further attempts when rate limit is reached
        $this->assertFalse($strategy->shouldRetry(3, 5, null));

        // Wait for rate limit window to pass
        sleep(2);

        // Should allow attempts again
        $this->assertTrue($strategy->shouldRetry(0, 5, null));
    }

    public function test_circuit_breaker_strategy_specific_behavior(): void
    {
        $strategy = new CircuitBreakerStrategy(
            innerStrategy: new FixedDelayStrategy,
            failureThreshold: 2,
            resetTimeout: 1
        );

        $exception = new RuntimeException('Test exception');

        // Circuit should start closed
        $this->assertTrue($strategy->shouldRetry(0, 5, null));

        // Two failures should open the circuit
        $this->assertTrue($strategy->shouldRetry(0, 5, $exception));
        $this->assertTrue($strategy->shouldRetry(1, 5, $exception));
        $this->assertFalse($strategy->shouldRetry(2, 5, $exception));

        // Wait for reset timeout
        sleep(2);

        // Circuit should be half-open and allow one attempt
        $this->assertTrue($strategy->shouldRetry(0, 5, null));

        // Successful attempt should close the circuit
        $this->assertTrue($strategy->shouldRetry(0, 5, null));
    }
}
