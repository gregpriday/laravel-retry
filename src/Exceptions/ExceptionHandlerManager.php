<?php

namespace GregPriday\LaravelRetry\Exceptions;

use GregPriday\LaravelRetry\Exceptions\Contracts\RetryableExceptionHandler;
use ReflectionClass;

class ExceptionHandlerManager
{
    /**
     * The registered exception handlers.
     *
     * @var array<RetryableExceptionHandler>
     */
    protected array $handlers = [];

    /**
     * Create a new exception handler manager instance.
     */
    public function __construct()
    {
    }

    /**
     * Register all handlers in the Handlers directory.
     */
    public function registerDefaultHandlers(): self
    {
        $handlersPath = __DIR__ . '/Handlers';
        $namespace = __NAMESPACE__ . '\\Handlers\\';

        if (!is_dir($handlersPath)) {
            return $this;
        }

        foreach (new \DirectoryIterator($handlersPath) as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            $className = $namespace . pathinfo($file->getFilename(), PATHINFO_FILENAME);

            // Skip the abstract BaseHandler class
            if ($className === $namespace . 'BaseHandler') {
                continue;
            }

            if (class_exists($className) && is_subclass_of($className, RetryableExceptionHandler::class)) {
                $reflection = new ReflectionClass($className);

                if (!$reflection->isAbstract()) {
                    $handler = new $className();

                    if ($handler->isApplicable()) {
                        $this->registerHandler($handler);
                    }
                }
            }
        }

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
     * @return array<class-string<\Throwable>>
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
            fn (RetryableExceptionHandler $handler) => !($handler instanceof $handlerClass)
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
}