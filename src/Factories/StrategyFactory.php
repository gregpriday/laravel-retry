<?php

declare(strict_types=1);

namespace GregPriday\LaravelRetry\Factories;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Utils\StrategyHelper;
use Illuminate\Support\Facades\Log;
use LogicException;
use ReflectionClass;
use ReflectionException;

/**
 * Factory for creating RetryStrategy instances.
 */
class StrategyFactory
{
    /**
     * Create a strategy instance based on an identifier (alias or class name) and options.
     *
     * @param  string  $strategyIdentifier  The class name or kebab-case alias of the strategy.
     * @param  array  $options  Options for the strategy constructor.
     * @return RetryStrategy The created strategy instance.
     *
     * @throws LogicException If the identifier is invalid or the class cannot be instantiated.
     */
    public static function make(string $strategyIdentifier, array $options = []): RetryStrategy
    {
        $strategyClass = $strategyIdentifier;
        $isAlias = false;
        $resolvedClass = null; // Keep track if alias resolution was attempted

        // Check if the identifier contains a namespace separator, which indicates it's likely a fully qualified class name
        if (!str_contains($strategyIdentifier, '\\')) {
            // If it doesn't have a namespace separator, try to treat it as an alias
            $resolvedClass = StrategyHelper::aliasToClass($strategyIdentifier);
            
            if ($resolvedClass !== null) {
                $isAlias = true;
                $strategyClass = $resolvedClass;
                
                // If no options were provided and this is an alias, attempt to get defaults from config
                if (empty($options)) {
                    $options = config("retry.strategies.{$strategyIdentifier}", []);
                }
            }
        }

        // Verify the class exists
        if (! class_exists($strategyClass)) {
            // Provide more specific error messages based on how we arrived here
            if ($isAlias) {
                // This case implies aliasToClass returned a class name, but class_exists failed.
                // This is unlikely but possible if the helper logic or environment is inconsistent.
                throw new LogicException("Resolved strategy class '{$strategyClass}' for alias '{$strategyIdentifier}' does not exist.");
            } elseif ($resolvedClass === null && !str_contains($strategyIdentifier, '\\')) {
                // It looked like an alias (no '\') but wasn't valid according to StrategyHelper.
                throw new LogicException("Invalid strategy alias '{$strategyIdentifier}'. No matching class found.");
            } else {
                // It looked like an FQCN (contained '\') but the class doesn't exist.
                throw new LogicException("Strategy class '{$strategyClass}' not found.");
            }
        }

        try {
            // Create a reflection class
            $reflection = new ReflectionClass($strategyClass);

            // Ensure we're instantiating a RetryStrategy
            if (! $reflection->implementsInterface(RetryStrategy::class)) {
                throw new LogicException("'{$strategyClass}' is not a valid RetryStrategy implementation.");
            }

            // Use the app() helper to resolve the strategy instance with dependencies
            return app($strategyClass, $options);

        } catch (ReflectionException $e) {
            // Log the error and fallback to a default strategy
            Log::error("Reflection error instantiating retry strategy '{$strategyClass}'" . 
                ($isAlias ? " (from alias '{$strategyIdentifier}')" : "") . ": {$e->getMessage()}", [
                'identifier' => $strategyIdentifier,
                'options'    => $options,
                'exception'  => $e,
            ]);

            // Fallback to a safe default strategy
            return new ExponentialBackoffStrategy();
        } catch (\Exception $e) {
            // Catch potential errors during instantiation via app()
            Log::error("Error creating retry strategy '{$strategyClass}'" . 
                ($isAlias ? " (from alias '{$strategyIdentifier}')" : "") . " via container: {$e->getMessage()}", [
                'identifier' => $strategyIdentifier,
                'options'    => $options,
                'exception'  => $e,
            ]);
            
            return new ExponentialBackoffStrategy();
        }
    }
} 