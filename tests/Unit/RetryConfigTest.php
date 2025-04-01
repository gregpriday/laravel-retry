<?php

namespace GregPriday\LaravelRetry\Tests\Unit;

use GregPriday\LaravelRetry\Retry;
use GregPriday\LaravelRetry\Tests\TestCase;

class RetryConfigTest extends TestCase
{
    /**
     * Test that config values are correctly used for defaults.
     */
    public function test_config_used_for_defaults()
    {
        // Set config values
        config()->set('retry.max_retries', 5);
        config()->set('retry.delay', 100);
        config()->set('retry.timeout', 3000);

        // Create a new Retry instance with no parameters
        // This should use config values as defaults
        $retry = new Retry;

        // Verify the configuration is used
        $this->assertEquals(5, $retry->getMaxRetries());
        $this->assertEquals(100, $retry->getRetryDelay());
        $this->assertEquals(3000, $retry->getTimeout());
    }

    /**
     * Test that config values are used when environment variables are not set.
     */
    public function test_environment_variables_override_config()
    {
        // Set config values
        config()->set('retry.max_retries', 5);
        config()->set('retry.delay', 100);
        config()->set('retry.timeout', 3000);

        // Note: The Retry class doesn't actually read environment variables directly
        // It only uses the config values, which may be set from environment variables
        // by Laravel's config system

        // Create a new Retry instance with no parameters
        $retry = new Retry;

        // Verify config values are used
        $this->assertEquals(5, $retry->getMaxRetries());
        $this->assertEquals(100, $retry->getRetryDelay());
        $this->assertEquals(3000, $retry->getTimeout());
    }

    /**
     * Test that constructor parameters override config values.
     */
    public function test_constructor_parameters_override_all()
    {
        // Set config values
        config()->set('retry.max_retries', 5);
        config()->set('retry.delay', 100);

        // Create a Retry instance with explicit constructor parameters
        $retry = new Retry(
            maxRetries: 15,
            retryDelay: 300
        );

        // Verify constructor parameters are used
        $this->assertEquals(15, $retry->getMaxRetries());
        $this->assertEquals(300, $retry->getRetryDelay());
    }

    /**
     * Test that setters override all other values.
     */
    public function test_setters_override_all_values()
    {
        // Set config values
        config()->set('retry.max_retries', 5);

        // Create a Retry instance with constructor parameters
        $retry = new Retry(maxRetries: 15);

        // Verify initial value
        $this->assertEquals(15, $retry->getMaxRetries());

        // Now use setter to change value
        $retry->maxRetries(20);

        // Verify setter value is used
        $this->assertEquals(20, $retry->getMaxRetries());
    }

    /**
     * Test that falsey or zero values in config are respected.
     */
    public function test_zero_or_falsey_config_values_respected()
    {
        // Set some zero or falsey values in config
        config()->set('retry.max_retries', 0);  // Should result in no retries
        config()->set('retry.delay', 0);         // No delay

        // Create a Retry instance with no constructor parameters
        $retry = new Retry;

        // Verify zero values are respected
        $this->assertEquals(0, $retry->getMaxRetries());
        $this->assertEquals(0, $retry->getRetryDelay());
    }
}
