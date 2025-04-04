<?php

namespace Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\ResponseContentStrategy;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionClass;

class ResponseContentStrategyTest extends TestCase
{
    /** @test */
    public function it_delegates_delay_calculation_to_inner_strategy()
    {
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->once())
            ->method('getDelay')
            ->with(2)
            ->willReturn(10.0);

        $strategy = new ResponseContentStrategy($innerStrategy);

        $this->assertEquals(10.0, $strategy->getDelay(2));
    }

    /** @test */
    public function it_delegates_to_inner_strategy_when_no_response_available()
    {
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->once())
            ->method('shouldRetry')
            ->with(2, 5, null)
            ->willReturn(true);

        $strategy = new ResponseContentStrategy($innerStrategy);

        $this->assertTrue($strategy->shouldRetry(2, 5));
    }

    /** @test */
    public function it_respects_max_attempts()
    {
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->never())->method('shouldRetry');

        $strategy = new ResponseContentStrategy($innerStrategy);

        $this->assertFalse($strategy->shouldRetry(5, 5));
    }

    /** @test */
    public function it_detects_retryable_content_based_on_regex_patterns()
    {
        $mockResponse = $this->createMockResponse('The server is temporarily unavailable, please try again later.');
        $mockRequest = new Request('GET', 'http://example.com');

        $exception = new RequestException('API Error', $mockRequest, $mockResponse);

        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->never())->method('shouldRetry');

        $strategy = new ResponseContentStrategy(
            $innerStrategy,
            retryableContentPatterns: ['/temporarily unavailable/i']
        );

        $this->assertTrue($strategy->shouldRetry(2, 5, $exception));
    }

    /** @test */
    public function it_detects_retryable_content_based_on_json_error_codes()
    {
        $jsonResponse = json_encode([
            'status' => 'error',
            'error'  => [
                'code'    => 'RATE_LIMITED',
                'message' => 'Too many requests',
            ],
        ]);
        $mockResponse = $this->createMockResponse($jsonResponse);
        $mockRequest = new Request('GET', 'http://example.com');

        $exception = new RequestException('API Error', $mockRequest, $mockResponse);

        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->never())->method('shouldRetry');

        $strategy = new ResponseContentStrategy(
            $innerStrategy,
            retryableErrorCodes: ['RATE_LIMITED'],
            errorCodePaths: ['error.code']
        );

        $this->assertTrue($strategy->shouldRetry(2, 5, $exception));
    }

    /** @test */
    public function it_handles_non_json_responses_gracefully()
    {
        $mockResponse = $this->createMockResponse('Not a valid JSON response');
        $mockRequest = new Request('GET', 'http://example.com');

        $exception = new RequestException('API Error', $mockRequest, $mockResponse);

        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->method('shouldRetry')->willReturn(false);

        $strategy = new ResponseContentStrategy(
            $innerStrategy,
            retryableErrorCodes: ['RATE_LIMITED'],
            errorCodePaths: ['error.code']
        );

        // Should not match the JSON criteria, but defer to inner strategy
        $this->assertFalse($strategy->shouldRetry(2, 5, $exception));
    }

    /** @test */
    public function it_uses_custom_content_checker_when_provided()
    {
        $mockResponse = $this->createMockResponse('Custom response format');
        $mockRequest = new Request('GET', 'http://example.com');

        $exception = new RequestException('API Error', $mockRequest, $mockResponse);

        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->never())->method('shouldRetry');

        $strategy = new ResponseContentStrategy($innerStrategy);
        $strategy->withContentChecker(function ($response) {
            return $response->getBody()->getContents() === 'Custom response format';
        });

        $this->assertTrue($strategy->shouldRetry(2, 5, $exception));
    }

    /** @test */
    public function it_allows_adding_additional_error_codes()
    {
        $jsonResponse = json_encode([
            'error_code' => 'CUSTOM_ERROR',
        ]);
        $mockResponse = $this->createMockResponse($jsonResponse);
        $mockRequest = new Request('GET', 'http://example.com');

        $exception = new RequestException('API Error', $mockRequest, $mockResponse);

        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->once())
            ->method('shouldRetry')
            ->with(2, 5, $exception)
            ->willReturn(false);

        $strategy = new ResponseContentStrategy(
            $innerStrategy,
            retryableErrorCodes: ['INITIAL_ERROR']
        );

        // Should not match with initial error codes
        $this->assertFalse($strategy->shouldRetry(2, 5, $exception));

        // Add the custom error code
        $strategy->withErrorCodes(['CUSTOM_ERROR']);

        // Now should match
        $this->assertTrue($strategy->shouldRetry(2, 5, $exception));
    }

    /** @test */
    public function it_allows_setting_custom_error_code_paths()
    {
        $jsonResponse = json_encode([
            'custom' => [
                'nested' => [
                    'path' => 'SPECIAL_ERROR',
                ],
            ],
        ]);
        $mockResponse = $this->createMockResponse($jsonResponse);
        $mockRequest = new Request('GET', 'http://example.com');

        $exception = new RequestException('API Error', $mockRequest, $mockResponse);

        $innerStrategy = $this->createMock(RetryStrategy::class);

        $strategy = new ResponseContentStrategy(
            $innerStrategy,
            retryableErrorCodes: ['SPECIAL_ERROR'],
            errorCodePaths: ['error.code'] // Default path that won't match
        );

        // Should not match with default paths
        $this->assertFalse($strategy->shouldRetry(2, 5, $exception));

        // Set the custom path
        $strategy->withErrorCodePaths(['custom.nested.path']);

        // Now should match
        $this->assertTrue($strategy->shouldRetry(2, 5, $exception));
    }

    /** @test */
    public function it_provides_access_to_inner_strategy()
    {
        $innerStrategy = new ExponentialBackoffStrategy;
        $strategy = new ResponseContentStrategy($innerStrategy);

        $this->assertSame($innerStrategy, $strategy->getInnerStrategy());
    }

    /**
     * Create a mock response object with the given body content
     */
    private function createMockResponse(string $body, int $statusCode = 500): ResponseInterface
    {
        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('getContents')->andReturn($body)->byDefault();
        $stream->shouldReceive('__toString')->andReturn($body)->byDefault();
        $stream->shouldReceive('rewind')->zeroOrMoreTimes()->byDefault();

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')->andReturn($stream)->byDefault();
        $response->shouldReceive('getStatusCode')->andReturn($statusCode)->byDefault();

        return $response;
    }

    /**
     * Set a private property value on an object for testing
     */
    private function setPrivateProperty(object $object, string $propertyName, $value): void
    {
        $reflection = new ReflectionClass(get_class($object));
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
