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

        $response = Http::withRetryStrategy($strategy)->get('https://example.com/api');

        $this->assertTrue($response['success']);
    }

    /** @test */
    public function with_rate_limit_handling_retries_on_429_response()
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'rate_limited'], 429, ['Retry-After' => '1'])
                ->push(['success' => true]),
        ]);

        $response = Http::withRateLimitHandling()->get('https://example.com/api');

        $this->assertTrue($response['success']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/api';
        }, 2); // Assert request was sent twice
    }
}
