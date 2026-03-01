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
     * Send multiple payloads in parallel using curl_multi.
     * Falls back to sequential sends if curl_multi is unavailable.
     */
    public function sendMultiple(array $payloads, int $timeout): void
    {
        if (! function_exists('curl_multi_init')) {
            foreach ($payloads as $payload) {
                $this->sendWithTimeout($payload, $timeout);
            }

            return;
        }

        $multi = curl_multi_init();
        $handles = [];

        foreach ($payloads as $i => $payload) {
            $ch = curl_init($this->endpoint);
            $body = json_encode($payload->toArray());
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-ErrorTag-Key: '.$this->apiKey,
                    'User-Agent: ErrorTag-Laravel/1.0',
                ],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }

        // Execute all requests in parallel.
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $ch) {
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);
    }

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
