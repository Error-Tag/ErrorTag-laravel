<?php

namespace ErrorTag\ErrorTag;

use ErrorTag\ErrorTag\Collectors\ApplicationContextCollector;
use ErrorTag\ErrorTag\Collectors\RequestContextCollector;
use ErrorTag\ErrorTag\Collectors\UserContextCollector;
use ErrorTag\ErrorTag\DataTransferObjects\ErrorPayload;
use ErrorTag\ErrorTag\DataTransferObjects\ExceptionData;
use ErrorTag\ErrorTag\Support\FingerprintGenerator;
use Illuminate\Http\Request;
use Throwable;

class ErrorTag
{
    protected array $customContext = [];

    public function __construct(
        protected ApplicationContextCollector $applicationCollector,
        protected RequestContextCollector $requestCollector,
        protected UserContextCollector $userCollector,
    ) {}

    /**
     * Add custom context that will be included with the next error report.
     */
    public function context(array $context): self
    {
        $this->customContext = array_merge($this->customContext, $context);

        return $this;
    }

    /**
     * Capture an exception and create an error payload.
     */
    public function captureException(Throwable $exception, ?Request $request = null): ?ErrorPayload
    {
        if (! $this->shouldCapture($exception)) {
            return null;
        }

        if (! $this->shouldSample()) {
            return null;
        }

        $payload = new ErrorPayload(
            fingerprint: FingerprintGenerator::generate($exception),
            exception: ExceptionData::fromThrowable(
                $exception,
                config('errortag-laravel.max_stack_trace_depth', 50),
                config('errortag-laravel.capture_stack_trace_args', false)
            ),
            request: $this->requestCollector->collect($request ?? request()),
            user: $this->userCollector->collect(),
            application: $this->applicationCollector->collect(),
            customContext: $this->customContext,
            release: config('errortag-laravel.release'),
            timestamp: now()->toIso8601String(),
        );

        // Clear custom context after capturing
        $this->customContext = [];

        return $payload;
    }

    /**
     * Determine if this exception should be captured.
     */
    protected function shouldCapture(Throwable $exception): bool
    {
        if (! config('errortag-laravel.enabled', true)) {
            return false;
        }

        if (! config('errortag-laravel.api_key')) {
            return false;
        }

        $ignoredExceptions = config('errortag-laravel.ignored_exceptions', []);

        foreach ($ignoredExceptions as $ignoredException) {
            if ($exception instanceof $ignoredException) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if this error should be sampled based on the sample rate.
     */
    protected function shouldSample(): bool
    {
        $sampleRate = config('errortag-laravel.sample_rate', 1.0);

        if ($sampleRate >= 1.0) {
            return true;
        }

        return mt_rand() / mt_getrandmax() <= $sampleRate;
    }

    /**
     * Clear any custom context.
     */
    public function clearContext(): self
    {
        $this->customContext = [];

        return $this;
    }
}
