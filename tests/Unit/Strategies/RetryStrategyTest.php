<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Strategies;

use Generator;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy;
use GregPriday\LaravelRetry\Strategies\DecorrelatedJitterStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\FibonacciBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class RetryStrategyTest extends TestCase
{
    /**
     * Data provider for strategy tests
     */
    public function strategyProvider(): Generator
    {
        yield 'exponential backoff' => [
            'strategy'             => new ExponentialBackoffStrategy(baseDelay: 5.0, multiplier: 2.0, maxDelay: 30),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay): array {
                $delay = $baseDelay * pow(2, $attempt);

                return [
                    'min' => (int) min($delay, 30),
                    'max' => (int) min($delay, 30),
                ];
            },
        ];

        yield 'exponential backoff with jitter' => [
            'strategy'             => new ExponentialBackoffStrategy(baseDelay: 5.0, multiplier: 2.0, maxDelay: 30, withJitter: true, jitterPercent: 0.2),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay): array {
                $delay = $baseDelay * pow(2, $attempt);
                $delay = min($delay, 30);

                return [
                    'min' => (int) ($delay * 0.8), // 20% less
                    'max' => (int) ($delay * 1.2), // 20% more
                ];
            },
        ];

        yield 'exponential backoff with custom jitter' => [
            'strategy'             => new ExponentialBackoffStrategy(baseDelay: 5.0, multiplier: 2.0, maxDelay: 30, withJitter: true, jitterPercent: 0.1),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay): array {
                $delay = $baseDelay * pow(2, $attempt);
                $delay = min($delay, 30);

                return [
                    'min' => (int) floor($delay * 0.89), // Allow a bit more margin for floating point precision
                    'max' => (int) ceil($delay * 1.11),  // Allow a bit more margin for floating point precision
                ];
            },
        ];

        yield 'linear backoff' => [
            'strategy'             => new LinearBackoffStrategy(baseDelay: 5.0, increment: 5, maxDelay: 30),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                $delay = $baseDelay + (5 * $attempt);

                return [
                    'min' => (int) min($delay, 30),
                    'max' => (int) min($delay, 30),
                ];
            },
        ];

        yield 'fibonacci backoff' => [
            'strategy'             => new FibonacciBackoffStrategy(baseDelay: 5.0, maxDelay: 30),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay): array {
                // Calculate Fibonacci number for the attempt
                $n = $attempt + 1;
                $fib = 1;
                if ($n > 1) {
                    $a = 1;
                    $b = 1;
                    for ($i = 3; $i <= $n; $i++) {
                        $c = $a + $b;
                        $a = $b;
                        $b = $c;
                    }
                    $fib = $b;
                }

                $delay = $baseDelay * $fib;
                $delay = min($delay, 30);

                return [
                    'min' => (int) $delay,
                    'max' => (int) $delay,
                ];
            },
        ];

        yield 'fibonacci backoff with jitter' => [
            'strategy'             => new FibonacciBackoffStrategy(baseDelay: 5.0, maxDelay: 30, withJitter: true, jitterPercent: 0.2),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay): array {
                // Calculate Fibonacci number for the attempt
                $n = $attempt + 1;
                $fib = 1;
                if ($n > 1) {
                    $a = 1;
                    $b = 1;
                    for ($i = 3; $i <= $n; $i++) {
                        $c = $a + $b;
                        $a = $b;
                        $b = $c;
                    }
                    $fib = $b;
                }

                $delay = $baseDelay * $fib;
                $delay = min($delay, 30);

                return [
                    'min' => (int) ($delay * 0.8), // 20% less
                    'max' => (int) ($delay * 1.2), // 20% more
                ];
            },
        ];

        yield 'fibonacci backoff with custom jitter' => [
            'strategy'             => new FibonacciBackoffStrategy(baseDelay: 5.0, maxDelay: 30, withJitter: true, jitterPercent: 0.1),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay): array {
                // Calculate Fibonacci number for the attempt
                $n = $attempt + 1;
                $fib = 1;
                if ($n > 1) {
                    $a = 1;
                    $b = 1;
                    for ($i = 3; $i <= $n; $i++) {
                        $c = $a + $b;
                        $a = $b;
                        $b = $c;
                    }
                    $fib = $b;
                }

                $delay = $baseDelay * $fib;
                $delay = min($delay, 30);

                return [
                    'min' => (int) floor($delay * 0.89), // Allow a bit more margin for floating point precision
                    'max' => (int) ceil($delay * 1.11),  // Allow a bit more margin for floating point precision
                ];
            },
        ];

        yield 'fixed delay' => [
            'strategy'             => new FixedDelayStrategy(baseDelay: 5.0),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) $baseDelay,
                    'max' => (int) $baseDelay,
                ];
            },
        ];

        yield 'fixed delay with jitter' => [
            'strategy'             => new FixedDelayStrategy(baseDelay: 5.0, withJitter: true, jitterPercent: 0.2),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) ($baseDelay * 0.79), // Allow a bit more margin for floating point precision
                    'max' => (int) ($baseDelay * 1.21), // Allow a bit more margin for floating point precision
                ];
            },
        ];

        yield 'fixed delay with custom jitter' => [
            'strategy'             => new FixedDelayStrategy(baseDelay: 5.0, withJitter: true, jitterPercent: 0.1),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) floor($baseDelay * 0.89), // Allow a bit more margin for floating point precision
                    'max' => (int) ceil($baseDelay * 1.11),  // Allow a bit more margin for floating point precision
                ];
            },
        ];

        yield 'decorrelated jitter' => [
            'strategy'             => new DecorrelatedJitterStrategy(baseDelay: 5.0, maxDelay: 30),
            'expectedDelayPattern' => function (int $attempt, float $baseDelay) {
                return [
                    'min' => (int) $baseDelay,
                    'max' => (int) min(30, $baseDelay * 3 * (1 << $attempt)),
                ];
            },
        ];

        yield 'rate limit' => [
            'strategy' => new RateLimitStrategy(
                innerStrategy: new FixedDelayStrategy(baseDelay: 5.0),
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
                innerStrategy: new FixedDelayStrategy(baseDelay: 5.0),
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
        $baseDelay = 5.0;

        // Test delays for first few attempts
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $delay = $strategy->getDelay($attempt);
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
        // Clear the rate limiter for this test
        $storageKey = 'test-rate-limit';
        RateLimiter::clear($storageKey);

        $strategy = new RateLimitStrategy(
            innerStrategy: new FixedDelayStrategy(baseDelay: 5.0),
            maxAttempts: 3,
            timeWindow: 1,
            storageKey: $storageKey
        );

        // Should allow initial attempts
        $this->assertTrue($strategy->shouldRetry(0, 5, null), 'First attempt should be allowed');
        $this->assertTrue($strategy->shouldRetry(1, 5, null), 'Second attempt should be allowed');
        $this->assertTrue($strategy->shouldRetry(2, 5, null), 'Third attempt should be allowed');

        // Should deny further attempts when rate limit is reached
        $this->assertFalse($strategy->shouldRetry(3, 5, null), 'Fourth attempt should be denied due to rate limit');

        // Wait for rate limit window to pass
        sleep(2);

        // Should allow attempts again
        $this->assertTrue($strategy->shouldRetry(0, 5, null), 'New attempt should be allowed after window reset');

        // Clean up
        RateLimiter::clear($storageKey);
    }

    public function test_circuit_breaker_strategy_specific_behavior(): void
    {
        $strategy = new CircuitBreakerStrategy(
            innerStrategy: new FixedDelayStrategy(baseDelay: 5.0),
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
