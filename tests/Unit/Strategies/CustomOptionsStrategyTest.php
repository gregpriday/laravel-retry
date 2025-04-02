<?php

namespace GregPriday\LaravelRetry\Tests\Unit\Strategies;

use Exception;
use GregPriday\LaravelRetry\Strategies\CustomOptionsStrategy;
use GregPriday\LaravelRetry\Strategies\ExponentialBackoffStrategy;
use GregPriday\LaravelRetry\Tests\TestCase;

class CustomOptionsStrategyTest extends TestCase
{
    private CustomOptionsStrategy $strategy;

    private ExponentialBackoffStrategy $innerStrategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->innerStrategy = $this->createMock(ExponentialBackoffStrategy::class);
        $this->strategy = new CustomOptionsStrategy(
            5.0,
            $this->innerStrategy,
            [
                'custom_option' => 'value',
                'max_delay'     => 30,
            ]
        );
    }

    /** @test */
    public function it_uses_custom_should_retry_callback_when_provided()
    {
        $called = false;
        $this->strategy->withShouldRetryCallback(function ($attempt, $maxAttempts, $exception, $options) use (&$called) {
            $called = true;
            $this->assertEquals('value', $options['custom_option']);

            return true;
        });

        $result = $this->strategy->shouldRetry(0, 3, new Exception('Test'));

        $this->assertTrue($called);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_falls_back_to_inner_strategy_for_should_retry_when_no_callback()
    {
        $this->innerStrategy->expects($this->once())
            ->method('shouldRetry')
            ->with(0, 3, $this->isInstanceOf(Exception::class))
            ->willReturn(true);

        $result = $this->strategy->shouldRetry(0, 3, new Exception('Test'));

        $this->assertTrue($result);
    }

    /** @test */
    public function it_uses_custom_delay_callback_when_provided()
    {
        $called = false;
        $this->strategy->withDelayCallback(function ($attempt, $baseDelay, $options) use (&$called) {
            $called = true;
            $this->assertEquals(30, $options['max_delay']);

            return 15;
        });

        $delay = $this->strategy->getDelay(0);

        $this->assertTrue($called);
        $this->assertEquals(15, $delay);
    }

    /** @test */
    public function it_falls_back_to_inner_strategy_for_delay_when_no_callback()
    {
        $this->innerStrategy->expects($this->once())
            ->method('getDelay')
            ->with(0)
            ->willReturn(10.0);

        $delay = $this->strategy->getDelay(0);

        $this->assertEquals(10.0, $delay);
    }

    /** @test */
    public function it_can_get_and_set_options()
    {
        $this->assertEquals('value', $this->strategy->getOption('custom_option'));
        $this->assertEquals(30, $this->strategy->getOption('max_delay'));
        $this->assertNull($this->strategy->getOption('non_existent'));
        $this->assertEquals('default', $this->strategy->getOption('non_existent', 'default'));

        $this->strategy->setOption('new_option', 'new_value');
        $this->assertEquals('new_value', $this->strategy->getOption('new_option'));
    }
}
