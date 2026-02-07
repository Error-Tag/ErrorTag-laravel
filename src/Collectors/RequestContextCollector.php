<?php

namespace ErrorTag\ErrorTag\Collectors;

use ErrorTag\ErrorTag\DataTransferObjects\RequestData;
use Illuminate\Http\Request;

class RequestContextCollector
{
    public function __construct(
        protected array $sanitizeHeaders = [],
        protected array $sanitizeFields = [],
        protected bool $captureBody = false,
    ) {}

    public function collect(?Request $request = null): ?RequestData
    {
        if (! $request) {
            return null;
        }

        try {
            return new RequestData(
                url: $request->fullUrl(),
                method: $request->method(),
                headers: $this->sanitizeHeaders($request->headers->all()),
                body: $this->captureBody ? $this->sanitizeBody($request->all()) : null,
                routeName: $request->route()?->getName(),
                controller: $this->getController($request),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (\Throwable $e) {
            // If request collection fails, return null rather than breaking error reporting
            \Illuminate\Support\Facades\Log::warning('ErrorTag failed to collect request context', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), array_map('strtolower', $this->sanitizeHeaders))) {
                $sanitized[$key] = ['[REDACTED]'];
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    protected function sanitizeBody(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), array_map('strtolower', $this->sanitizeFields))) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeBody($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    protected function getController(Request $request): ?string
    {
        $action = $request->route()?->getAction();

        if (! $action) {
            return null;
        }

        if (isset($action['controller'])) {
            return is_string($action['controller']) ? $action['controller'] : get_class($action['controller']);
        }

        return null;
    }
}
