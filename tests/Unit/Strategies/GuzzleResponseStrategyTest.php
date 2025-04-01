<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Strategies;

use Exception;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\GuzzleResponseStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class GuzzleResponseStrategyTest extends TestCase
{
    private GuzzleResponseStrategy $strategy;

    private ExponentialBackoffStrategy $fallbackStrategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fallbackStrategy = $this->createMock(ExponentialBackoffStrategy::class);
        $this->strategy = new GuzzleResponseStrategy($this->fallbackStrategy);
    }

    /**
     * @test
     *
     * @dataProvider retryHeaderProvider
     */
    public function it_correctly_processes_retry_after_headers(
        array $headers,
        int $expectedDelay,
        string $message,
        bool $isTimeBased = false
    ): void {
        $response = new Response(429, $headers);
        $exception = $this->createRequestException($response);

        // Configure the test to think this exception was from shouldRetry
        $this->setLastException($exception);

        $delay = $this->strategy->getDelay(0, 5);

        if ($isTimeBased) {
            // Allow for a small timing difference (±15 seconds) for time-based tests
            $this->assertGreaterThanOrEqual($expectedDelay - 15, $delay, $message);
            $this->assertLessThanOrEqual($expectedDelay + 15, $delay, $message);
        } else {
            $this->assertEquals($expectedDelay, $delay, $message);
        }
    }

    public function retryHeaderProvider(): array
    {
        $now = time();

        return [
            'seconds-based-retry-after' => [
                ['Retry-After' => '30'],
                30,
                'Should use seconds directly from Retry-After header',
                false,
            ],
            'date-based-retry-after' => [
                ['Retry-After' => gmdate('D, d M Y H:i:s T', $now + 45)],
                45,
                'Should calculate seconds from date-based Retry-After header',
                true,
            ],
            'x-ratelimit-reset-timestamp' => [
                ['X-RateLimit-Reset' => (string) ($now + 135)],
                135,
                'Should calculate seconds from X-RateLimit-Reset timestamp',
                true,
            ],
            'x-ratelimit-reset-past-timestamp' => [
                ['X-RateLimit-Reset' => '45'],
                0,
                'Should return 0 for past Unix timestamps in X-RateLimit-Reset header',
                false,
            ],
            'x-retry-in' => [
                ['X-Retry-In' => '15'],
                15,
                'Should use seconds from X-Retry-In header',
                false,
            ],
            'prefers-retry-after-over-others' => [
                [
                    'Retry-After'       => '30',
                    'X-RateLimit-Reset' => (string) ($now + 60),
                    'X-Retry-In'        => '15',
                ],
                30,
                'Should prefer Retry-After over other headers',
                false,
            ],
        ];
    }

    /** @test */
    public function it_respects_max_delay_limit(): void
    {
        // Create the strategy with a max delay of 60 seconds and a mock fallback
        $fallbackStrategy = $this->createMock(ExponentialBackoffStrategy::class);
        $strategy = new GuzzleResponseStrategy(
            fallbackStrategy: $fallbackStrategy,
            maxDelay: 60
        );

        // Create a response with a Retry-After header greater than max delay
        $response = new Response(429, ['Retry-After' => '120']);
        $exception = $this->createRequestException($response);

        // Ensure fallback shouldRetry returns true so we can get to the delay calculation
        $fallbackStrategy->method('shouldRetry')->willReturn(true);

        // Call shouldRetry first to set up the context and last exception
        $strategy->shouldRetry(0, 3, $exception);

        $delay = $strategy->getDelay(0, 5);

        $this->assertEquals(60, $delay, 'Should cap delay at maxDelay value');
    }

    /** @test */
    public function it_falls_back_to_fallback_strategy_when_no_headers_present(): void
    {
        $response = new Response(500);
        $exception = $this->createRequestException($response);

        $this->fallbackStrategy->expects($this->once())
            ->method('getDelay')
            ->with(0, 5.0)
            ->willReturn(10);

        $this->setLastException($exception);

        $delay = $this->strategy->getDelay(0, 5);

        $this->assertEquals(10, $delay, 'Should use fallback strategy when no retry headers present');
    }

    /**
     * @test
     *
     * @dataProvider retryDecisionProvider
     */
    public function it_makes_correct_retry_decisions(
        int $statusCode,
        array $headers,
        bool $expectedDecision,
        string $message
    ): void {
        $response = new Response($statusCode, $headers);
        $exception = $this->createRequestException($response);

        // Configure fallback to allow retry
        $this->fallbackStrategy->method('shouldRetry')->willReturn(true);

        $shouldRetry = $this->strategy->shouldRetry(0, 3, $exception);

        $this->assertEquals($expectedDecision, $shouldRetry, $message);
    }

    public function retryDecisionProvider(): array
    {
        return [
            'retry-on-500' => [
                500,
                [],
                true,
                'Should retry on server errors',
            ],
            'retry-on-429' => [
                429,
                [],
                true,
                'Should retry on rate limit errors',
            ],
            'retry-on-400-with-retry-after' => [
                400,
                ['Retry-After' => '30'],
                true,
                'Should retry on client errors with retry header',
            ],
            'no-retry-on-400-without-headers' => [
                400,
                [],
                false,
                'Should not retry on client errors without retry headers',
            ],
            'retry-on-400-with-rate-limit' => [
                400,
                ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '60'],
                true,
                'Should retry on client errors with rate limit headers',
            ],
        ];
    }

    /** @test */
    public function it_uses_default_exponential_backoff_if_no_fallback_provided(): void
    {
        $strategy = new GuzzleResponseStrategy;
        $response = new Response(500);
        $exception = $this->createRequestException($response);

        $this->setLastException($exception);

        $delay = $strategy->getDelay(0, 5);

        // ExponentialBackoffStrategy with default settings would return 5 for first attempt
        $this->assertEquals(5, $delay, 'Should use default ExponentialBackoffStrategy');
    }

    /** @test */
    public function it_handles_non_request_exceptions_gracefully(): void
    {
        $this->fallbackStrategy->expects($this->once())
            ->method('shouldRetry')
            ->willReturn(true);

        $shouldRetry = $this->strategy->shouldRetry(0, 3, new Exception('Generic error'));

        $this->assertTrue($shouldRetry, 'Should defer to fallback strategy for non-request exceptions');
    }

    /** @test */
    public function it_handles_exceptions_without_responses_gracefully(): void
    {
        $exception = new RequestException(
            'Error without response',
            new Request('GET', 'http://example.com')
        );

        $this->fallbackStrategy->expects($this->once())
            ->method('shouldRetry')
            ->willReturn(true);

        $shouldRetry = $this->strategy->shouldRetry(0, 3, $exception);

        $this->assertTrue($shouldRetry, 'Should handle exceptions without responses');
    }

    /**
     * Helper to create a RequestException with a Response.
     */
    private function createRequestException(Response $response): RequestException
    {
        return new RequestException(
            'Error Communicating with Server',
            new Request('GET', 'http://example.com'),
            $response
        );
    }

    /**
     * Helper to simulate the last exception context.
     */
    private function setLastException(RequestException $exception): void
    {
        // First call shouldRetry to set up the context
        $this->strategy->shouldRetry(0, 3, $exception);
    }

    /** @test */
    public function it_handles_malformed_retry_after_headers(): void
    {
        $response = new Response(429, ['Retry-After' => 'invalid']);
        $exception = $this->createRequestException($response);

        $this->fallbackStrategy->expects($this->once())
            ->method('getDelay')
            ->willReturn(10);

        $this->setLastException($exception);

        $delay = $this->strategy->getDelay(0, 5);

        $this->assertEquals(10, $delay, 'Should fall back when Retry-After header is malformed');
    }

    /** @test */
    public function it_handles_multiple_header_values(): void
    {
        $response = new Response(429, [
            'Retry-After' => ['30', '60'],  // First value should be used
        ]);
        $exception = $this->createRequestException($response);

        $this->setLastException($exception);

        $delay = $this->strategy->getDelay(0, 5);

        $this->assertEquals(30, $delay, 'Should use first value when multiple header values exist');
    }
}
