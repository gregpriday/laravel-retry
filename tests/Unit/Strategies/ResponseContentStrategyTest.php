<?php

namespace Tests\Unit\Strategies;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\ResponseContentStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ResponseContentStrategyTest extends TestCase
{
    /** @test */
    public function it_delegates_delay_calculation_to_inner_strategy()
    {
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->once())
            ->method('getDelay')
            ->with(2, 5.0)
            ->willReturn(10);
            
        $strategy = new ResponseContentStrategy($innerStrategy);
        
        $this->assertEquals(10, $strategy->getDelay(2, 5.0));
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
        
        $exception = new RuntimeException('API Error');
        $this->setPrivateProperty($exception, 'response', $mockResponse);
        
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
            'error' => [
                'code' => 'RATE_LIMITED',
                'message' => 'Too many requests'
            ]
        ]);
        $mockResponse = $this->createMockResponse($jsonResponse);
        
        $exception = new RuntimeException('API Error');
        $this->setPrivateProperty($exception, 'response', $mockResponse);
        
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
        
        $exception = new RuntimeException('API Error');
        $this->setPrivateProperty($exception, 'response', $mockResponse);
        
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
        
        $exception = new RuntimeException('API Error');
        $this->setPrivateProperty($exception, 'response', $mockResponse);
        
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->never())->method('shouldRetry');
        
        $strategy = new ResponseContentStrategy($innerStrategy);
        $strategy->withContentChecker(function ($response) {
            return $response->getBody() === 'Custom response format';
        });
        
        $this->assertTrue($strategy->shouldRetry(2, 5, $exception));
    }
    
    /** @test */
    public function it_allows_adding_additional_error_codes()
    {
        $jsonResponse = json_encode([
            'error_code' => 'CUSTOM_ERROR'
        ]);
        $mockResponse = $this->createMockResponse($jsonResponse);
        
        $exception = new RuntimeException('API Error');
        $this->setPrivateProperty($exception, 'response', $mockResponse);
        
        $innerStrategy = $this->createMock(RetryStrategy::class);
        $innerStrategy->expects($this->never())->method('shouldRetry');
        
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
                    'path' => 'SPECIAL_ERROR'
                ]
            ]
        ]);
        $mockResponse = $this->createMockResponse($jsonResponse);
        
        $exception = new RuntimeException('API Error');
        $this->setPrivateProperty($exception, 'response', $mockResponse);
        
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
        $innerStrategy = new ExponentialBackoffStrategy();
        $strategy = new ResponseContentStrategy($innerStrategy);
        
        $this->assertSame($innerStrategy, $strategy->getInnerStrategy());
    }
    
    /**
     * Create a mock response object with the given body content
     */
    private function createMockResponse(string $body)
    {
        return new class($body) {
            private $body;
            
            public function __construct($body)
            {
                $this->body = $body;
            }
            
            public function getBody()
            {
                return $this->body;
            }
            
            public function body()
            {
                return $this->body;
            }
        };
    }
    
    /**
     * Set a private property value on an object for testing
     */
    private function setPrivateProperty(object $object, string $propertyName, $value): void
    {
        $reflection = new \ReflectionClass($object);
        
        if (!$reflection->hasProperty($propertyName)) {
            $reflection = new \ReflectionClass($reflection->getName());
            $property = new \ReflectionProperty($reflection->getName(), $propertyName);
            $property->setAccessible(true);
            $property->setValue($object, $value);
        } else {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue($object, $value);
        }
    }
} 