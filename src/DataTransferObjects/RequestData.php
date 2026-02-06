<?php

namespace ErrorTag\ErrorTag\DataTransferObjects;

class RequestData
{
    public function __construct(
        public readonly string $url,
        public readonly string $method,
        public readonly array $headers,
        public readonly ?array $body = null,
        public readonly ?string $routeName = null,
        public readonly ?string $controller = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'url' => $this->url,
            'method' => $this->method,
            'headers' => $this->headers,
            'body' => $this->body,
            'route_name' => $this->routeName,
            'controller' => $this->controller,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
        ], fn ($value) => $value !== null);
    }
}
