<?php

namespace GregPriday\LaravelRetry\Exceptions\Handlers;

class GuzzleHandler extends BaseHandler
{
    protected function getHandlerPatterns(): array
    {
        return [
            '/cURL error/i',
            '/SSL connection/i',
            '/certificate has expired/i',
            '/Could not resolve host/i',
            '/Operation timed out/i',
        ];
    }

    protected function getHandlerExceptions(): array
    {
        return [
            \GuzzleHttp\Exception\ConnectException::class,
            \GuzzleHttp\Exception\ServerException::class,
            \GuzzleHttp\Exception\TooManyRedirectsException::class,
            \GuzzleHttp\Exception\RequestException::class,
        ];
    }

    public function isApplicable(): bool
    {
        return class_exists(\GuzzleHttp\Client::class);
    }
}
