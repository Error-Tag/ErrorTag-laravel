<?php

namespace ErrorTag\ErrorTag;

use ErrorTag\ErrorTag\Collectors\ApplicationContextCollector;
use ErrorTag\ErrorTag\Collectors\RequestContextCollector;
use ErrorTag\ErrorTag\Collectors\UserContextCollector;
use ErrorTag\ErrorTag\Commands\ErrorTagCommand;
use ErrorTag\ErrorTag\Http\ErrorTagApiClient;
use ErrorTag\ErrorTag\Jobs\SendErrorToErrorTagJob;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class ErrorTagServiceProvider extends PackageServiceProvider
{
    protected static bool $capturing = false;

    /** @var array<int, \ErrorTag\ErrorTag\DataTransferObjects\ErrorPayload> */
    protected static array $pendingPayloads = [];

    /** @var array<string, bool> Fingerprints already queued this request — prevents duplicate sends */
    protected static array $seen = [];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('errortag-laravel')
            ->hasConfigFile()
            ->hasCommand(ErrorTagCommand::class);
    }

    public function packageRegistered(): void
    {
        // Register the ErrorTag API client as a singleton
        $this->app->singleton(ErrorTagApiClient::class, function ($app) {
            return new ErrorTagApiClient(
                apiKey: config('errortag-laravel.api_key', ''),
                endpoint: config('errortag-laravel.api_endpoint', 'https://errortag.dev/api/errors'),
                timeout: config('errortag-laravel.timeout', 5),
            );
        });

        // Register context collectors
        $this->app->singleton(ApplicationContextCollector::class);

        $this->app->singleton(RequestContextCollector::class, function ($app) {
            return new RequestContextCollector(
                sanitizeHeaders: config('errortag-laravel.sanitize_headers', []),
                sanitizeFields: config('errortag-laravel.sanitize_fields', []),
                captureBody: config('errortag-laravel.capture_request_body', false),
            );
        });

        $this->app->singleton(UserContextCollector::class, function ($app) {
            return new UserContextCollector(
                captureUser: config('errortag-laravel.capture_user', true),
            );
        });

        // Register the main ErrorTag class as a singleton
        $this->app->singleton(ErrorTag::class, function ($app) {
            return new ErrorTag(
                applicationCollector: $app->make(ApplicationContextCollector::class),
                requestCollector: $app->make(RequestContextCollector::class),
                userCollector: $app->make(UserContextCollector::class),
            );
        });
    }

    public function packageBooted(): void
    {
        // Register exception reporting hook
        if (config('errortag-laravel.enabled', true) && config('errortag-laravel.api_key')) {
            $this->registerExceptionHandler();
            $this->registerErrorHandler();
            $this->registerShutdownHandler();

            // Flush captured errors after the HTTP response has been sent to the user.
            // This means error reporting never adds latency to the end-user's request.
            $this->app->terminating(function () {
                $this->flushPendingErrors();
            });
        }
    }

    /**
     * Queue the payload for sending after the response has been sent to the user.
     * Falls back to immediate sync send if not in an HTTP context (e.g. CLI/queue workers).
     */
    protected function sendError($payload): void
    {
        if (! $payload) {
            return;
        }

        // Deduplicate: skip if we already queued this exact fingerprint this request.
        if (isset(self::$seen[$payload->fingerprint])) {
            return;
        }
        self::$seen[$payload->fingerprint] = true;

        if (config('errortag-laravel.use_queue', false)) {
            SendErrorToErrorTagJob::dispatch($payload->toArray());

            return;
        }

        // Defer: hold the payload until after the response is flushed.
        // The terminating() hook (registered in packageBooted) will flush them.
        self::$pendingPayloads[] = $payload;
    }

    /**
     * Send all pending payloads. Called from the terminating hook (after response)
     * or from the shutdown handler (for fatal errors).
     */
    protected function flushPendingErrors(): void
    {
        if (empty(self::$pendingPayloads)) {
            return;
        }

        $payloads = self::$pendingPayloads;
        self::$pendingPayloads = [];
        self::$seen = [];

        try {
            $apiClient = $this->app->make(ErrorTagApiClient::class);
            $timeout = config('errortag-laravel.sync_timeout', 5);

            if (count($payloads) === 1) {
                // Fast path: single payload, no overhead.
                $apiClient->sendWithTimeout($payloads[0], $timeout);
            } else {
                // Multiple payloads: send in parallel via curl_multi.
                $apiClient->sendMultiple($payloads, $timeout);
            }
        } catch (Throwable $e) {
            if (config('app.debug')) {
                Log::debug('ErrorTag flush failed', ['error' => $e->getMessage()]);
            }
        }
    }

    protected function registerExceptionHandler(): void
    {
        /** @var \Illuminate\Foundation\Exceptions\Handler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->reportable(function (Throwable $e) { // @phpstan-ignore-line
            // Prevent re-entrant capture and errors from the package itself.
            if (self::$capturing) {
                return;
            }

            $file = $e->getFile();
            if (str_contains($file, 'ErrorTag') || str_contains($file, 'errortag')) {
                return;
            }

            self::$capturing = true;

            try {
                $errorTag = $this->app->make(ErrorTag::class);
                $payload = $errorTag->captureException($e);

                $this->sendError($payload);
            } catch (Throwable $errorTagException) {
                // Never let ErrorTag break the application
                // Silently log the failure
                Log::error('ErrorTag failed to capture exception', [
                    'error' => $errorTagException->getMessage(),
                ]);
            } finally {
                self::$capturing = false;
            }
        });
    }

    protected function registerErrorHandler(): void
    {
        if (! config('errortag-laravel.capture_php_errors', true)) {
            return;
        }

        // Capture PHP errors (warnings, notices, deprecations, etc.)
        set_error_handler(function ($severity, $message, $file, $line) {
            // Prevent ErrorTag from capturing its own errors (avoid infinite loops)
            if (self::$capturing) {
                return false;
            }

            // Don't capture errors from ErrorTag itself
            if (str_contains($file, 'ErrorTag') || str_contains($file, 'errortag')) {
                return false;
            }

            // Don't capture errors that are suppressed with @
            if (! (error_reporting() & $severity)) {
                return false;
            }

            // Only capture warnings and above by default — skip notices and deprecations
            // which are high-frequency and rarely actionable.
            $minLevel = config('errortag-laravel.minimum_error_level', E_WARNING | E_ERROR | E_USER_ERROR | E_USER_WARNING);
            if (! ($severity & $minLevel)) {
                return false;
            }

            self::$capturing = true;

            try {
                $errorTag = $this->app->make(ErrorTag::class);

                // Create an exception from the PHP error
                $exception = new \ErrorException($message, 0, $severity, $file, $line);

                $payload = $errorTag->captureException($exception);

                $this->sendError($payload);
            } catch (Throwable $e) {
                // Don't break the app if ErrorTag fails
                Log::error('ErrorTag error handler failed', ['error' => $e->getMessage()]);
            } finally {
                self::$capturing = false;
            }

            // Let PHP handle the error normally as well
            return false;
        });
    }

    protected function registerShutdownHandler(): void
    {
        register_shutdown_function(function () {
            $error = error_get_last();

            // Capture all fatal errors including parse errors
            if ($error === null || ! in_array($error['type'], [
                E_ERROR,           // Fatal run-time errors
                E_PARSE,           // Compile-time parse errors (syntax errors)
                E_CORE_ERROR,      // Fatal errors during PHP's initial startup
                E_CORE_WARNING,    // Warnings during PHP's initial startup
                E_COMPILE_ERROR,   // Fatal compile-time errors
                E_COMPILE_WARNING, // Compile-time warnings
                E_USER_ERROR,      // User-generated error
            ])) {
                return;
            }

            try {
                $errorTag = $this->app->make(ErrorTag::class);

                // Create a synthetic exception from the fatal error
                $exception = new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );

                $payload = $errorTag->captureException($exception);

                if ($payload) {
                    // For fatal errors the terminating hook won't run, so send directly.
                    self::$pendingPayloads[] = $payload;
                    $this->flushPendingErrors();
                }
            } catch (Throwable $e) {
                // Can't do much here since we're already in a fatal error state
                // Try to log it if possible
                @error_log('ErrorTag shutdown handler failed: '.$e->getMessage());
            }
        });
    }
}
