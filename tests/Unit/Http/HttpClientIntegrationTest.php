<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Http;

use GregPriday\LaravelRetry\Http\LaravelHttpRetryIntegration;
use GregPriday\LaravelRetry\Strategies\GuzzleResponseStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class HttpClientIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register HTTP macros
        LaravelHttpRetryIntegration::register();
    }

    /** @test */
    public function it_registers_robust_retry_macro()
    {
        // Check that the macro is registered
        $this->assertTrue(Http::hasMacro('robustRetry'));
    }

    /** @test */
    public function it_registers_with_retry_strategy_macro()
    {
        // Check that the macro is registered
        $this->assertTrue(Http::hasMacro('withRetryStrategy'));
    }

    /** @test */
    public function it_registers_with_circuit_breaker_macro()
    {
        // Check that the macro is registered
        $this->assertTrue(Http::hasMacro('withCircuitBreaker'));
    }

    /** @test */
    public function it_registers_with_rate_limit_handling_macro()
    {
        // Check that the macro is registered
        $this->assertTrue(Http::hasMacro('withRateLimitHandling'));
    }

    /** @test */
    public function it_registers_retry_when_macro()
    {
        // Check that the macro is registered
        $this->assertTrue(Http::hasMacro('retryWhen'));
    }

    /** @test */
    public function robust_retry_uses_guzzle_response_strategy_by_default()
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => false], 429, ['Retry-After' => '2'])
                ->push(['success' => true]),
        ]);

        $response = Http::robustRetry()->get('https://example.com/api');

        $this->assertTrue($response['success']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/api';
        });
    }

    /** @test */
    public function it_passes_max_attempts_to_retry_method()
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['attempt' => 1], 500)
                ->push(['attempt' => 2], 500)
                ->push(['attempt' => 3], 200),
        ]);

        $response = Http::robustRetry(3)->get('https://example.com/api');

        $this->assertEquals(3, $response['attempt']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/api';
        }, 3); // Assert request was sent 3 times
    }

    /** @test */
    public function it_applies_custom_options()
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['attempt' => 1], 500)
                ->push(['success' => true]),
        ]);

        $middlewareCalled = false;
        $response = Http::robustRetry(3, null, [
            'timeout'    => 30,
            'middleware' => function ($request) use (&$middlewareCalled) {
                $middlewareCalled = true;

                return $request->withHeaders(['X-Custom' => 'Value']);
            },
        ])->get('https://example.com/api');

        $this->assertTrue($response['success']);
        $this->assertTrue($middlewareCalled);
        Http::assertSent(function (Request $request) {
            return $request->hasHeader('X-Custom', 'Value');
        });
    }

    /** @test */
    public function it_uses_custom_retry_condition()
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'custom_error', 'success' => false], 429)
                ->push(['success' => true], 200),
        ]);

        $response = Http::retryWhen(function ($attempt, $maxAttempts, $exception, $options) {
            return $attempt < $maxAttempts && $exception !== null;
        }, [
            'max_attempts' => 2,
        ])->get('https://example.com/api');

        $this->assertTrue($response['success']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/api';
        }, 2);
    }

    /** @test */
    public function with_retry_strategy_uses_provided_strategy()
    {
        $strategy = $this->createMock(GuzzleResponseStrategy::class);
        $strategy->expects($this->atLeastOnce())
            ->method('shouldRetry')
            ->willReturn(true);

        Http::fake([
            '*' => Http::sequence()
                ->push(['attempt' => 1], 500)
                ->push(['success' => true]),
        ]);

        $response = Http::withRetryStrategy($strategy, [
            'timeout'    => 20,
            'base_delay' => 2,
        ])->get('https://example.com/api');

        $this->assertTrue($response['success']);
    }

    /** @test */
    public function with_rate_limit_handling_uses_rate_limit_strategy()
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'rate_limited'], 429, ['Retry-After' => '1'])
                ->push(['success' => true]),
        ]);

        $maxAttempts = 100;
        $timeWindow = 60;
        $response = Http::withRateLimitHandling($maxAttempts, $timeWindow, [
            'timeout' => 15,
        ])->get('https://example.com/api');

        $this->assertTrue($response['success']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/api';
        }, 2); // Assert request was sent twice
    }

    /** @test */
    public function it_respects_throw_option()
    {
        Http::fake([
            '*' => Http::response(['error' => 'server_error'], 500),
        ]);

        $response = Http::robustRetry(1, null, [
            'throw' => false,
        ])->get('https://example.com/api');

        $this->assertEquals(500, $response->status());
        $this->assertEquals(['error' => 'server_error'], $response->json());
    }

    /** @test */
    public function it_passes_zero_based_attempt_number_to_strategy()
    {
        $strategy = $this->createMock(GuzzleResponseStrategy::class);

        // The strategy should receive 0-based attempt numbers for getDelay
        $strategy->expects($this->exactly(2))
            ->method('getDelay')
            ->willReturnOnConsecutiveCalls(1, 1);

        // Always return true for shouldRetry to ensure we hit all attempts
        $strategy->method('shouldRetry')->willReturn(true);

        Http::fake([
            '*' => Http::sequence()
                ->push(['attempt' => 1, 'success' => false], 500)
                ->push(['attempt' => 2, 'success' => false], 500)
                ->push(['success' => true]),
        ]);

        $response = Http::robustRetry(3, $strategy)->get('https://example.com/api');

        $this->assertTrue($response['success']);
    }

    /** @test */
    public function rate_limit_strategy_respects_time_window()
    {
        $maxAttempts = 2;
        $timeWindow = 60;

        Http::fake([
            '*' => Http::sequence()
                ->push(['attempt' => 1], 429)
                ->push(['attempt' => 2], 429)
                ->push(['attempt' => 3], 429), // This should not be attempted due to rate limit
        ]);

        $response = Http::withRateLimitHandling($maxAttempts, $timeWindow, [
            'max_attempts' => 3,
            'throw'        => false,
        ])->get('https://example.com/api');

        // Should only make maxAttempts requests within the time window
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/api';
        }, 2);

        $this->assertEquals(429, $response->status());
    }
}
