<?php

namespace GregPriday\LaravelRetry\Http;

use Illuminate\Support\ServiceProvider;

class HttpClientServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        // No services to register
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        // Register HTTP client macros
        LaravelHttpRetryIntegration::register();
    }
}
