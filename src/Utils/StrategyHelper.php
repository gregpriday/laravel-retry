<?php

declare(strict_types=1);

namespace GregPriday\LaravelRetry\Utils;

use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;

class StrategyHelper
{
    /**
     * The suffix used for all strategy classes.
     */
    private const STRATEGY_SUFFIX = 'Strategy';

    /**
     * The namespace where strategy classes are located.
     */
    private const STRATEGY_NAMESPACE = 'GregPriday\\LaravelRetry\\Strategies\\';

    /**
     * Convert a strategy class name to its kebab-case alias.
     *
     * Examples:
     * - ExponentialBackoffStrategy -> exponential-backoff
     * - LinearDelayStrategy -> linear-delay
     *
     * @param  string  $className  Fully qualified class name or short name
     * @return string|null The kebab-case alias or null if conversion fails
     */
    public static function classToAlias(string $className): ?string
    {
        // Get the short class name if a FQCN is provided
        if (str_contains($className, '\\')) {
            $className = substr($className, strrpos($className, '\\') + 1);
        }

        // Ensure it ends with "Strategy"
        if (! str_ends_with($className, self::STRATEGY_SUFFIX)) {
            return null;
        }

        // Remove the suffix
        $baseName = substr($className, 0, -strlen(self::STRATEGY_SUFFIX));

        // Convert PascalCase to kebab-case
        return Str::kebab($baseName);
    }

    /**
     * Convert a kebab-case alias to its fully qualified strategy class name.
     *
     * Examples:
     * - exponential-backoff -> GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy
     * - linear-delay -> GregPriday\LaravelRetry\Strategies\LinearDelayStrategy
     *
     * @param  string  $alias  The kebab-case alias
     * @return string|null The fully qualified class name or null if conversion fails
     */
    public static function aliasToClass(string $alias): ?string
    {
        // Convert kebab-case to PascalCase
        $baseName = Str::studly($alias);

        // Append the suffix
        $className = $baseName.self::STRATEGY_SUFFIX;

        // Prepend the namespace
        $fqcn = self::STRATEGY_NAMESPACE.$className;

        // Check if the class actually exists
        if (! class_exists($fqcn)) {
            return null;
        }

        // Verify it implements the RetryStrategy interface
        try {
            $reflection = new ReflectionClass($fqcn);
            if (! $reflection->implementsInterface(RetryStrategy::class)) {
                return null;
            }
        } catch (ReflectionException $e) {
            return null;
        }

        return $fqcn;
    }

    /**
     * Get all available strategy aliases by scanning the Strategies directory.
     *
     * @return array<string> Array of kebab-case strategy aliases
     */
    public static function getAllStrategyAliases(): array
    {
        $aliases = [];
        $pattern = dirname(__DIR__).'/Strategies/*Strategy.php';

        foreach (glob($pattern) as $file) {
            $className = basename($file, '.php');
            if ($alias = self::classToAlias($className)) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }
}
