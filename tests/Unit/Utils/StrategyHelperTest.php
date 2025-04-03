<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Utils;

use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Strategies\FixedDelayStrategy;
use GregPriday\LaravelRetry\Strategies\LinearBackoffStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;
use GregPriday\LaravelRetry\Utils\StrategyHelper;

class StrategyHelperTest extends TestCase
{
    /**
     * Test classToAlias with valid class names
     *
     * @dataProvider strategyProvider
     */
    public function test_class_to_alias_with_valid_class(string $class, string $expectedAlias): void
    {
        // Test with short class name
        $shortClassName = substr($class, strrpos($class, '\\') + 1);
        $this->assertEquals($expectedAlias, StrategyHelper::classToAlias($shortClassName));

        // Test with FQCN
        $this->assertEquals($expectedAlias, StrategyHelper::classToAlias($class));
    }

    /**
     * Test classToAlias with invalid class names
     */
    public function test_class_to_alias_with_invalid_class(): void
    {
        // Class name without 'Strategy' suffix
        $this->assertNull(StrategyHelper::classToAlias('ExponentialBackoff'));

        // Non-strategy class
        $this->assertNull(StrategyHelper::classToAlias('SomeRandomClass'));
    }

    /**
     * Test aliasToClass with valid aliases
     *
     * @dataProvider strategyProvider
     */
    public function test_alias_to_class_with_valid_alias(string $expectedClass, string $alias): void
    {
        $this->assertEquals($expectedClass, StrategyHelper::aliasToClass($alias));
    }

    /**
     * Test aliasToClass with invalid aliases
     */
    public function test_alias_to_class_with_invalid_alias(): void
    {
        // Non-existent alias
        $this->assertNull(StrategyHelper::aliasToClass('non-existent-strategy'));

        // Alias that would resolve to a non-existent class
        $this->assertNull(StrategyHelper::aliasToClass('some-random-name'));
    }

    /**
     * Test getAllStrategyAliases
     */
    public function test_get_all_strategy_aliases(): void
    {
        $aliases = StrategyHelper::getAllStrategyAliases();

        // Check that common strategies are in the list
        $this->assertContains('exponential-backoff', $aliases);
        $this->assertContains('fixed-delay', $aliases);
        $this->assertContains('linear-backoff', $aliases);

        // Check counts
        $this->assertGreaterThanOrEqual(3, count($aliases), 'Should have at least 3 strategy aliases');
    }

    /**
     * Data provider for strategy classes and their aliases
     */
    public function strategyProvider(): array
    {
        return [
            'exponential backoff' => [ExponentialBackoffStrategy::class, 'exponential-backoff'],
            'fixed delay'         => [FixedDelayStrategy::class, 'fixed-delay'],
            'linear backoff'      => [LinearBackoffStrategy::class, 'linear-backoff'],
        ];
    }
}
