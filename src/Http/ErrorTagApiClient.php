<?php

namespace ErrorTag\ErrorTag\Http;

use ErrorTag\ErrorTag\DataTransferObjects\ErrorPayload;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ErrorTagApiClient
{
    public function __construct(
        protected string $apiKey,
        protected string $endpoint,
        protected int $timeout = 5,
    ) {}

    /**
     * Send an error payload to the ErrorTag API.
     */
    public function send(ErrorPayload $payload): bool
    {
        try {
            $response = $this->client()->post($this->endpoint, $payload->toArray());

            return $response->successful(); // @phpstan-ignore-line

        } catch (\Exception $e) {
            // Log the failure but don't throw - we don't want ErrorTag to break the app
            report($e);

            return false;
        }
    }

    /**
     * Send an error payload with custom timeout.
     * Useful for sync sends where we want a shorter timeout.
     */
    public function sendWithTimeout(ErrorPayload $payload, int $timeout): bool
    {
        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'X-ErrorTag-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->withUserAgent('ErrorTag-Laravel/1.0')
                ->post($this->endpoint, $payload->toArray());

            return $response->successful(); // @phpstan-ignore-line

        } catch (\Exception $e) {
            // Silently fail - timeout or network error shouldn't break the app
            return false;
        }
    }

    /**
     * Test the connection to the ErrorTag API.
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->client()->get(str_replace('/api/errors', '/api/health', $this->endpoint));

            return $response->successful(); // @phpstan-ignore-line

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get a configured HTTP client instance.
     */
    protected function client(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'X-ErrorTag-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->withUserAgent('ErrorTag-Laravel/1.0');
    }
}
