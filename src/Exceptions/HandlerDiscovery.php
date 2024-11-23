<?php

namespace GregPriday\LaravelRetry\Exceptions;

use GregPriday\LaravelRetry\Contracts\RetryableExceptionHandler;
use Illuminate\Support\Collection;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class HandlerDiscovery
{
    /**
     * Default handler paths.
     *
     * @var array<string>
     */
    protected array $paths = [];

    /**
     * Create a new handler discovery instance.
     *
     * @param  array<string>  $paths  Additional paths to search for handlers
     */
    public function __construct(array $paths = [])
    {
        // Add default package handlers path
        $this->paths[] = __DIR__.'/Handlers';

        // Add application handlers path if it exists
        if (function_exists('app_path')) {
            $this->paths[] = app_path('Exceptions/Retry/Handlers');
        }

        // Add any custom paths
        $this->paths = array_merge($this->paths, $paths);
    }

    /**
     * Add a path to search for handlers.
     */
    public function addPath(string $path): self
    {
        $this->paths[] = $path;

        return $this;
    }

    /**
     * Get all configured handler paths.
     *
     * @return array<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * Set the handler paths.
     *
     * @param  array<string>  $paths
     */
    public function setPaths(array $paths): self
    {
        $this->paths = $paths;

        return $this;
    }

    /**
     * Discover all available handlers.
     *
     * @return array<RetryableExceptionHandler>
     */
    public function discover(): array
    {
        return $this->findHandlerFiles()
            ->map(function (string $file) {
                return $this->loadHandlerClass($file);
            })
            ->filter()
            ->filter(function (RetryableExceptionHandler $handler) {
                return $handler->isApplicable();
            })
            ->values()
            ->all();
    }

    /**
     * Find all potential handler files.
     *
     * @return Collection<string>
     */
    protected function findHandlerFiles(): Collection
    {
        $finder = new Finder();

        return Collection::make($this->paths)
            ->filter(function (string $path) {
                return is_dir($path);
            })
            ->flatMap(function (string $path) use ($finder) {
                return iterator_to_array(
                    $finder->files()
                        ->in($path)
                        ->name('*Handler.php')
                        ->notName('BaseHandler.php')
                );
            })
            ->map(function (\SplFileInfo $file) {
                return $file->getRealPath();
            });
    }

    /**
     * Load a handler class from a file.
     */
    protected function loadHandlerClass(string $file): ?RetryableExceptionHandler
    {
        $className = $this->getClassNameFromFile($file);

        if (! $className || ! class_exists($className)) {
            return null;
        }

        $reflection = new ReflectionClass($className);

        if ($reflection->isAbstract() ||
            ! $reflection->implementsInterface(RetryableExceptionHandler::class)) {
            return null;
        }

        return new $className();
    }

    /**
     * Get the fully qualified class name from a file.
     */
    protected function getClassNameFromFile(string $file): ?string
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        // Extract namespace
        if (! preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatches)) {
            return null;
        }

        // Extract class name
        if (! preg_match('/class\s+(\w+)/', $contents, $classMatches)) {
            return null;
        }

        return $namespaceMatches[1].'\\'.$classMatches[1];
    }

    /**
     * Remove a path from the search paths.
     */
    public function removePath(string $path): self
    {
        $this->paths = array_filter($this->paths, function ($existingPath) use ($path) {
            return $existingPath !== $path;
        });

        return $this;
    }

    /**
     * Clear all search paths.
     */
    public function clearPaths(): self
    {
        $this->paths = [];

        return $this;
    }
}
