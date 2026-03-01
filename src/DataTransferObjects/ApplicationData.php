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
        public readonly ?string $appUrl = null,
        public readonly ?string $appLocale = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'laravel_version' => $this->laravelVersion,
            'php_version' => $this->phpVersion,
            'environment' => $this->environment,
            'server_name' => $this->serverName,
            'app_name' => $this->appName,
            'app_url' => $this->appUrl,
            'app_locale' => $this->appLocale,
        ], fn ($value) => $value !== null);
    }
}
