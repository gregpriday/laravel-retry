<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use GregPriday\LaravelRetry\Contracts\RetryableExceptionHandler;
use GregPriday\LaravelRetry\Exceptions\ExceptionHandlerManager;
use GregPriday\LaravelRetry\Exceptions\HandlerDiscovery;
use GregPriday\LaravelRetry\Tests\TestCase;
use Mockery;

class ExceptionHandlerManagerTest extends TestCase
{
    protected ExceptionHandlerManager $manager;

    protected HandlerDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a fresh manager for each test
        $this->discovery = Mockery::mock(HandlerDiscovery::class);
        $this->manager = new ExceptionHandlerManager($this->discovery);
    }

    public function test_registering_multiple_custom_handlers()
    {
        // Create mock handlers
        $handler1 = $this->createMockHandler(['pattern1'], ['Exception1']);
        $handler2 = $this->createMockHandler(['pattern2'], ['Exception2']);

        // Register handlers
        $this->manager->registerHandler($handler1);
        $this->manager->registerHandler($handler2);

        // Verify handlers are registered
        $handlers = $this->manager->getHandlers();
        $this->assertCount(2, $handlers);
        $this->assertContains($handler1, $handlers);
        $this->assertContains($handler2, $handlers);

        // Verify patterns are merged
        $patterns = $this->manager->getAllPatterns();
        $this->assertCount(2, $patterns);
        $this->assertContains('pattern1', $patterns);
        $this->assertContains('pattern2', $patterns);

        // Verify exceptions are merged
        $exceptions = $this->manager->getAllExceptions();
        $this->assertCount(2, $exceptions);
        $this->assertContains('Exception1', $exceptions);
        $this->assertContains('Exception2', $exceptions);
    }

    public function test_removing_specific_handler()
    {
        // Create a concrete handler class for testing
        $handler1 = new class implements RetryableExceptionHandler
        {
            public function getPatterns(): array
            {
                return ['pattern1'];
            }

            public function getExceptions(): array
            {
                return ['Exception1'];
            }

            public function isApplicable(): bool
            {
                return true;
            }
        };

        $handler2 = new class implements RetryableExceptionHandler
        {
            public function getPatterns(): array
            {
                return ['pattern2'];
            }

            public function getExceptions(): array
            {
                return ['Exception2'];
            }

            public function isApplicable(): bool
            {
                return true;
            }
        };

        // Register handlers
        $this->manager->registerHandler($handler1);
        $this->manager->registerHandler($handler2);

        // Verify both handlers are registered
        $this->assertCount(2, $this->manager->getHandlers());

        // Get the class name of handler1
        $handler1Class = get_class($handler1);

        // Remove handler1
        $this->manager->removeHandler($handler1Class);

        // Verify handler1 was removed
        $handlers = $this->manager->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertFalse($this->manager->hasHandler($handler1Class));

        // Verify handler2 is still registered
        $this->assertTrue($this->manager->hasHandler(get_class($handler2)));
    }

    public function test_clearing_all_handlers()
    {
        // Create and register mock handlers
        $handler1 = $this->createMockHandler(['pattern1'], ['Exception1']);
        $handler2 = $this->createMockHandler(['pattern2'], ['Exception2']);

        $this->manager->registerHandler($handler1);
        $this->manager->registerHandler($handler2);

        // Verify both handlers are registered
        $this->assertCount(2, $this->manager->getHandlers());

        // Clear all handlers
        $this->manager->clearHandlers();

        // Verify no handlers remain
        $this->assertCount(0, $this->manager->getHandlers());
    }

    public function test_handler_path_management()
    {
        // Mock the discovery's path management methods
        $this->discovery->shouldReceive('addPath')
            ->once()
            ->with('test/path')
            ->andReturnSelf();

        $this->discovery->shouldReceive('getPaths')
            ->once()
            ->andReturn(['test/path']);

        $this->discovery->shouldReceive('removePath')
            ->once()
            ->with('test/path')
            ->andReturnSelf();

        $this->discovery->shouldReceive('clearPaths')
            ->once()
            ->andReturnSelf();

        // Test operations
        $this->manager->addHandlerPath('test/path');
        $this->assertEquals(['test/path'], $this->manager->getHandlerPaths());
        $this->manager->removeHandlerPath('test/path');
        $this->manager->clearHandlerPaths();
    }

    public function test_registering_handlers_from_path()
    {
        // Create mock handler
        $handler = $this->createMockHandler(['pattern'], ['Exception']);

        // Mock discovery
        $this->discovery->shouldReceive('addPath')
            ->once()
            ->with('test/path')
            ->andReturnSelf();

        $this->discovery->shouldReceive('discover')
            ->once()
            ->andReturn([$handler]);

        // Register handler from path
        $this->manager->registerHandlersFromPath('test/path');

        // Verify handler was registered
        $handlers = $this->manager->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertContains($handler, $handlers);
    }

    public function test_handler_discovery_setter_getter()
    {
        // Create a new discovery instance
        $newDiscovery = Mockery::mock(HandlerDiscovery::class);

        // Set the discovery instance
        $this->manager->setDiscovery($newDiscovery);

        // Verify the discovery instance was set
        $this->assertSame($newDiscovery, $this->manager->getDiscovery());
    }

    /**
     * Create a mock RetryableExceptionHandler.
     */
    private function createMockHandler(array $patterns, array $exceptions): RetryableExceptionHandler
    {
        $handler = Mockery::mock(RetryableExceptionHandler::class);

        $handler->shouldReceive('getPatterns')
            ->andReturn($patterns);

        $handler->shouldReceive('getExceptions')
            ->andReturn($exceptions);

        $handler->shouldReceive('isApplicable')
            ->andReturn(true);

        return $handler;
    }
}
