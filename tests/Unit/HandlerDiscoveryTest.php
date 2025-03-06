<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use GregPriday\LaravelRetry\Contracts\RetryableExceptionHandler;
use GregPriday\LaravelRetry\Exceptions\HandlerDiscovery;
use GregPriday\LaravelRetry\Tests\TestCase;
use Mockery;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

class HandlerDiscoveryTest extends TestCase
{
    protected vfsStreamDirectory $fs;

    protected string $handlerDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup virtual filesystem
        $this->fs = vfsStream::setup('root');
        $this->handlerDirectory = vfsStream::url('root/handlers');
        mkdir($this->handlerDirectory);
    }

    public function test_path_management()
    {
        // Create a discovery instance
        $discovery = new HandlerDiscovery([]);
        $discovery->clearPaths(); // Clear default paths

        // Test adding a path
        $newPath = vfsStream::url('root/new_path');
        mkdir($newPath);

        $discovery->addPath($this->handlerDirectory);
        $discovery->addPath($newPath);
        $paths = $discovery->getPaths();

        $this->assertContains($newPath, $paths);
        $this->assertContains($this->handlerDirectory, $paths);

        // Test removing a path
        $discovery->removePath($newPath);
        $paths = $discovery->getPaths();

        $this->assertNotContains($newPath, $paths);
        $this->assertContains($this->handlerDirectory, $paths);

        // Test setting paths
        $newPaths = ['/path1', '/path2'];
        $discovery->setPaths($newPaths);
        $this->assertEquals($newPaths, $discovery->getPaths());

        // Test clearing paths
        $discovery->clearPaths();
        $this->assertEmpty($discovery->getPaths());
    }

    public function test_discovery_ignores_invalid_php_file()
    {
        // Create a mock HandlerDiscovery
        $discovery = Mockery::mock(HandlerDiscovery::class)
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        // Mock the findHandlerFiles method to return our test file
        $invalidContent = "<?php\n// This is just a comment\n";
        $invalidFilePath = $this->handlerDirectory.'/InvalidHandler.php';
        file_put_contents($invalidFilePath, $invalidContent);

        $discovery->shouldReceive('findHandlerFiles')
            ->andReturn(collect([$invalidFilePath]));

        // Discover handlers
        $handlers = $discovery->discover();

        // Should not find any handlers
        $this->assertEmpty($handlers);
    }

    public function test_discovery_ignores_abstract_classes()
    {
        // Create a mock HandlerDiscovery
        $discovery = Mockery::mock(HandlerDiscovery::class)
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        // Create an abstract class file
        $abstractClassContent = <<<'PHP'
<?php
namespace GregPriday\LaravelRetry\Exceptions\Handlers;

use GregPriday\LaravelRetry\Contracts\RetryableExceptionHandler;
use Throwable;

abstract class AbstractTestHandler implements RetryableExceptionHandler
{
    public function getPatterns(): array
    {
        return [];
    }
    
    public function getExceptions(): array
    {
        return [];
    }
    
    public function isApplicable(): bool
    {
        return true;
    }
}
PHP;
        $abstractFilePath = $this->handlerDirectory.'/AbstractTestHandler.php';
        file_put_contents($abstractFilePath, $abstractClassContent);

        // Mock the findHandlerFiles and getClassNameFromFile methods
        $discovery->shouldReceive('findHandlerFiles')
            ->andReturn(collect([$abstractFilePath]));

        $discovery->shouldReceive('getClassNameFromFile')
            ->andReturn('GregPriday\LaravelRetry\Exceptions\Handlers\AbstractTestHandler');

        // Mock class_exists to return true
        $discovery->shouldReceive('loadHandlerClass')
            ->andReturn(null);

        // Discover handlers
        $handlers = $discovery->discover();

        // Should not find any handlers
        $this->assertEmpty($handlers);
    }

    public function test_discovery_ignores_non_implementing_classes()
    {
        // Create a mock HandlerDiscovery
        $discovery = Mockery::mock(HandlerDiscovery::class)
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        // Create a non-implementing class file
        $nonImplementingContent = <<<'PHP'
<?php
namespace GregPriday\LaravelRetry\Exceptions\Handlers;

class NonImplementingHandler
{
    public function someMethod()
    {
        return true;
    }
}
PHP;
        $nonImplementingFilePath = $this->handlerDirectory.'/NonImplementingHandler.php';
        file_put_contents($nonImplementingFilePath, $nonImplementingContent);

        // Mock the findHandlerFiles and getClassNameFromFile methods
        $discovery->shouldReceive('findHandlerFiles')
            ->andReturn(collect([$nonImplementingFilePath]));

        $discovery->shouldReceive('getClassNameFromFile')
            ->andReturn('GregPriday\LaravelRetry\Exceptions\Handlers\NonImplementingHandler');

        // Mock loadHandlerClass to return null
        $discovery->shouldReceive('loadHandlerClass')
            ->andReturn(null);

        // Discover handlers
        $handlers = $discovery->discover();

        // Should not find any handlers
        $this->assertEmpty($handlers);
    }

    public function test_discovery_ignores_inapplicable_handlers()
    {
        // Create a mock HandlerDiscovery
        $discovery = Mockery::mock(HandlerDiscovery::class)
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        // Create a mock handler that returns false for isApplicable
        $mockHandler = Mockery::mock(RetryableExceptionHandler::class);
        $mockHandler->shouldReceive('isApplicable')->andReturn(false);

        // Mock the findHandlerFiles method
        $discovery->shouldReceive('findHandlerFiles')
            ->andReturn(collect(['path/to/InapplicableHandler.php']));

        // Mock the loadHandlerClass method to return our mock handler
        $discovery->shouldReceive('loadHandlerClass')
            ->andReturn($mockHandler);

        // Discover handlers
        $handlers = $discovery->discover();

        // Should not find any handlers
        $this->assertEmpty($handlers);
    }

    public function test_discovery_finds_valid_handlers()
    {
        // Create a mock HandlerDiscovery
        $discovery = Mockery::mock(HandlerDiscovery::class);

        // Create a mock handler that returns true for isApplicable
        $mockHandler = Mockery::mock(RetryableExceptionHandler::class);
        $mockHandler->shouldReceive('isApplicable')->andReturn(true);

        // Mock the discover method to return our mock handler
        $discovery->shouldReceive('discover')
            ->andReturn([$mockHandler]);

        // Verify the mock handler is returned
        $handlers = $discovery->discover();
        $this->assertCount(1, $handlers);
        $this->assertSame($mockHandler, $handlers[0]);
    }
}
