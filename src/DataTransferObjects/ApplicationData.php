<?php

namespace ErrorTag\ErrorTag\DataTransferObjects;

class ApplicationData
{
    public function __construct(
        public readonly string $laravelVersion,
        public readonly string $phpVersion,
        public readonly string $environment,
        public readonly string $serverName,
        public readonly ?string $appName = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'laravel_version' => $this->laravelVersion,
            'php_version' => $this->phpVersion,
            'environment' => $this->environment,
            'server_name' => $this->serverName,
            'app_name' => $this->appName,
        ], fn ($value) => $value !== null);
    }
}
