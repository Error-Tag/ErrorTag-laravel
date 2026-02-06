<?php

namespace ErrorTag\ErrorTag\Jobs;

use ErrorTag\ErrorTag\DataTransferObjects\ErrorPayload;
use ErrorTag\ErrorTag\Http\ErrorTagApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendErrorToErrorTagJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public array $errorPayload,
    ) {
        $this->onConnection(config('errortag-laravel.queue_connection'));
        $this->onQueue(config('errortag-laravel.queue_name', 'default'));
    }

    public function handle(ErrorTagApiClient $client): void
    {
        $payload = $this->reconstructPayload();

        $client->send($payload);
    }

    /**
     * Reconstruct the ErrorPayload from the serialized array.
     */
    protected function reconstructPayload(): ErrorPayload
    {
        // The payload is already serialized as an array for queue storage
        // We'll need to reconstruct it when sending
        return new ErrorPayload(
            fingerprint: $this->errorPayload['fingerprint'],
            exception: new \ErrorTag\ErrorTag\DataTransferObjects\ExceptionData(
                message: $this->errorPayload['exception']['message'],
                type: $this->errorPayload['exception']['type'],
                file: $this->errorPayload['exception']['file'],
                line: $this->errorPayload['exception']['line'],
                stackTrace: $this->errorPayload['exception']['stack_trace'],
                code: $this->errorPayload['exception']['code'] ?? null,
            ),
            request: isset($this->errorPayload['request']) ? new \ErrorTag\ErrorTag\DataTransferObjects\RequestData(
                url: $this->errorPayload['request']['url'],
                method: $this->errorPayload['request']['method'],
                headers: $this->errorPayload['request']['headers'],
                body: $this->errorPayload['request']['body'] ?? null,
                routeName: $this->errorPayload['request']['route_name'] ?? null,
                controller: $this->errorPayload['request']['controller'] ?? null,
                ip: $this->errorPayload['request']['ip'] ?? null,
                userAgent: $this->errorPayload['request']['user_agent'] ?? null,
            ) : null,
            user: isset($this->errorPayload['user']) ? new \ErrorTag\ErrorTag\DataTransferObjects\UserData(
                id: $this->errorPayload['user']['id'],
                email: $this->errorPayload['user']['email'] ?? null,
                name: $this->errorPayload['user']['name'] ?? null,
                attributes: $this->errorPayload['user']['attributes'] ?? [],
            ) : null,
            application: new \ErrorTag\ErrorTag\DataTransferObjects\ApplicationData(
                laravelVersion: $this->errorPayload['application']['laravel_version'],
                phpVersion: $this->errorPayload['application']['php_version'],
                environment: $this->errorPayload['application']['environment'],
                serverName: $this->errorPayload['application']['server_name'],
                appName: $this->errorPayload['application']['app_name'] ?? null,
            ),
            customContext: $this->errorPayload['custom_context'] ?? [],
            release: $this->errorPayload['release'] ?? null,
            timestamp: $this->errorPayload['timestamp'],
        );
    }
}
