<?php

namespace GregPriday\LaravelRetry\Exceptions;

use GregPriday\LaravelRetry\Contracts\RetryableExceptionHandler;
use Throwable;

class ExceptionHandlerManager
{
    /**
     * The registered exception handlers.
     *
     * @var array<RetryableExceptionHandler>
     */
    protected array $handlers = [];

    /**
     * The handler discovery instance.
     */
    protected HandlerDiscovery $discovery;

    /**
     * Create a new exception handler manager instance.
     */
    public function __construct(?HandlerDiscovery $discovery = null)
    {
        $this->discovery = $discovery ?? new HandlerDiscovery;
    }

    /**
     * Register all handlers in the configured paths.
     */
    public function registerDefaultHandlers(): self
    {
        $handlers = $this->discovery->discover();

        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }

        return $this;
    }

    /**
     * Add a custom path to search for handlers.
     */
    public function addHandlerPath(string $path): self
    {
        $this->discovery->addPath($path);

        return $this;
    }

    /**
     * Register a new exception handler.
     */
    public function registerHandler(RetryableExceptionHandler $handler): self
    {
        $this->handlers[] = $handler;

        return $this;
    }

    /**
     * Get all registered patterns from all handlers.
     *
     * @return array<string>
     */
    public function getAllPatterns(): array
    {
        if (empty($this->handlers)) {
            return [];
        }

        return array_unique(
            array_merge(...array_map(
                fn (RetryableExceptionHandler $handler) => $handler->getPatterns(),
                $this->handlers
            ))
        );
    }

    /**
     * Get all registered exceptions from all handlers.
     *
     * @return array<class-string<Throwable>>
     */
    public function getAllExceptions(): array
    {
        if (empty($this->handlers)) {
            return [];
        }

        return array_unique(
            array_merge(...array_map(
                fn (RetryableExceptionHandler $handler) => $handler->getExceptions(),
                $this->handlers
            ))
        );
    }

    /**
     * Get all registered handlers.
     *
     * @return array<RetryableExceptionHandler>
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * Check if a specific handler is registered.
     */
    public function hasHandler(string $handlerClass): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof $handlerClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove a specific handler.
     */
    public function removeHandler(string $handlerClass): self
    {
        $this->handlers = array_filter(
            $this->handlers,
            fn (RetryableExceptionHandler $handler) => ! ($handler instanceof $handlerClass)
        );

        return $this;
    }

    /**
     * Remove all registered handlers.
     */
    public function clearHandlers(): self
    {
        $this->handlers = [];

        return $this;
    }

    /**
     * Get the handler discovery instance.
     */
    public function getDiscovery(): HandlerDiscovery
    {
        return $this->discovery;
    }

    /**
     * Set the handler discovery instance.
     */
    public function setDiscovery(HandlerDiscovery $discovery): self
    {
        $this->discovery = $discovery;

        return $this;
    }

    /**
     * Register handlers from a specific path immediately.
     */
    public function registerHandlersFromPath(string $path): self
    {
        $this->addHandlerPath($path);
        $handlers = $this->discovery->discover();

        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }

        return $this;
    }

    /**
     * Get all configured handler paths.
     *
     * @return array<string>
     */
    public function getHandlerPaths(): array
    {
        return $this->discovery->getPaths();
    }

    /**
     * Remove a handler path.
     */
    public function removeHandlerPath(string $path): self
    {
        $this->discovery->removePath($path);

        return $this;
    }

    /**
     * Clear all handler paths.
     */
    public function clearHandlerPaths(): self
    {
        $this->discovery->clearPaths();

        return $this;
    }
}
