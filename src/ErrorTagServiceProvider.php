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
    protected static bool $capturing = false;

    /** @var array<int, \ErrorTag\ErrorTag\DataTransferObjects\ErrorPayload> */
    protected static array $pendingPayloads = [];
    /** @var array<int, \ErrorTag\ErrorTag\DataTransferObjects\ErrorPayload> */
    protected static array $pendingPayloads = [];

    /** @var array<string, bool> Fingerprints already queued this request — prevents duplicate sends */
    protected static array $seen = [];
    /** @var array<string, bool> Fingerprints already queued this request — prevents duplicate sends */
    protected static array $seen = [];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('errortag-laravel')
            ->hasConfigFile()
            ->hasCommand(ErrorTagCommand::class);
    }
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
        // Register context collectors
        $this->app->singleton(ApplicationContextCollector::class);

        $this->app->singleton(RequestContextCollector::class, function ($app) {
            return new RequestContextCollector(
                sanitizeHeaders: config('errortag-laravel.sanitize_headers', []),
                sanitizeFields: config('errortag-laravel.sanitize_fields', []),
                captureBody: config('errortag-laravel.capture_request_body', false),
            );
        });
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

        // Register exception handler early so it can capture errors that occur
        // during the boot phase (e.g. ParseError in routes, config, etc.)
        if (config('errortag-laravel.enabled', true) && config('errortag-laravel.api_key')) {
            $this->registerExceptionHandler();
            $this->registerShutdownHandler();

            // Register the terminating hook HERE (not in packageBooted) so that deferred
            // payloads are always flushed even when an exception during boot prevents
            // packageBooted() from running (e.g. ParseError in routes/web.php).
            if (! $this->app->runningInConsole()) {
                $this->app->terminating(function () {
                    $this->flushPendingErrors();
                });
            }
        }
    }

    public function packageBooted(): void
    {
        if (! config('errortag-laravel.enabled', true) || ! config('errortag-laravel.api_key')) {
            return;
        }

        $this->registerErrorHandler();

        // Reset the deduplication cache between queue jobs so that retried jobs
        // (or separate jobs that trigger the same error) are always reported.
        if ($this->app->runningInConsole()) {
            try {
                \Illuminate\Support\Facades\Queue::after(function () {
                    self::$seen = [];
                });
                \Illuminate\Support\Facades\Queue::failing(function () {
                    self::$seen = [];
                });
            } catch (\Throwable) {
                // Queue may not be configured — skip silently.
            }
        }

        // Enable DB query logging so we can attach queries to error reports.
        // This is a no-op if the DB facade isn't available (e.g. during early boot).
        try {
            \Illuminate\Support\Facades\DB::enableQueryLog();
        } catch (\Throwable) {
            // DB may not be available yet — skip silently.
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
    /**
     * Queue the payload for sending after the response has been sent to the user.
     * Falls back to immediate sync send if not in an HTTP context (e.g. CLI/queue workers).
     */
    protected function sendError($payload): void
    {
        if (! $payload) {
            return;
        }

        // Deduplicate: skip if we already sent this exact fingerprint this request.
        if (isset(self::$seen[$payload->fingerprint])) {
            return;
        }
        self::$seen[$payload->fingerprint] = true;

        if (config('errortag-laravel.use_queue', false)) {
            SendErrorToErrorTagJob::dispatch($payload->toArray());

            return;
        }

        // In CLI/queue workers: send immediately since there is no HTTP response cycle.
        if ($this->app->runningInConsole()) {
            try {
                $apiClient = $this->app->make(ErrorTagApiClient::class);
                $timeout = config('errortag-laravel.sync_timeout', 5);
                $apiClient->sendWithTimeout($payload, $timeout);
            } catch (Throwable $e) {
                if (config('app.debug')) {
                    Log::debug('ErrorTag send failed', ['error' => $e->getMessage()]);
                }
            }

            return;
        }

        // HTTP context: queue the payload for sending after the response is flushed.
        // The terminating() hook (registered in packageRegistered) calls flushPendingErrors()
        // once the response is fully sent. In PHP-FPM environments this means other
        // workers are free to accept the ingest request while this worker finalises —
        // eliminating the deadlock that occurs when the dashboard monitors itself.
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
        $payloads = self::$pendingPayloads;
        self::$pendingPayloads = [];
        self::$seen = [];

        try {
            $apiClient = $this->app->make(ErrorTagApiClient::class);
            $timeout = config('errortag-laravel.sync_timeout', 2);
            $maxAttempts = max(1, (int) config('errortag-laravel.sync_retries', 2));

            if (count($payloads) === 1) {
                // Retry up to sync_retries times on failure (covers transient network blips).
                $sent = false;
                for ($attempt = 1; $attempt <= $maxAttempts && ! $sent; $attempt++) {
                    $sent = $apiClient->sendWithTimeout($payloads[0], $timeout);
                }
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
        $this->app->make(ExceptionHandler::class) // @phpstan-ignore-line
            ->reportable(function (Throwable $e) {
                // Prevent re-entrant capture.
                if (self::$capturing) {
                    return true;
                }

                // Only skip exceptions originating from inside the package's own src/ directory.
                $file = str_replace('\\', '/', $e->getFile());
                $packageSrc = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
                if (str_starts_with($file, $packageSrc)) {
                    return true;
                }

                self::$capturing = true;

                try {
                    $errorTag = $this->app->make(ErrorTag::class);
                    $payload = $errorTag->captureException($e);
                    $this->sendError($payload);
                } catch (Throwable $errorTagException) {
                    Log::error('ErrorTag failed to capture exception', [
                        'error' => $errorTagException->getMessage(),
                    ]);
                } finally {
                    self::$capturing = false;
                }

                // Return true so Laravel continues its own reporting (logs, etc.)
                return true;
            });
    }

    protected function registerErrorHandler(): void
    {
        if (! config('errortag-laravel.capture_php_errors', true)) {
            return;
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
        // Capture PHP errors (warnings, notices, deprecations, etc.)
        set_error_handler(function ($severity, $message, $file, $line) {
            // Prevent ErrorTag from capturing its own errors (avoid infinite loops)
            if (self::$capturing) {
                return false;
            }

            // Don't capture errors from ErrorTag's own src/ directory.
            $packageSrc = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
            if (str_starts_with(str_replace('\\', '/', $file), $packageSrc)) {
                return false;
            }

            // Don't capture errors that are suppressed with @
            if (! (error_reporting() & $severity)) {
                return false;
            }
            // Don't capture errors that are suppressed with @
            if (! (error_reporting() & $severity)) {
                return false;
            }

            // Only capture errors at or above the configured minimum level.
            // Defaults to E_ALL via config, meaning all PHP errors are captured.
            // Users can raise this to E_WARNING | E_ERROR to reduce noise from notices/deprecations.
            $minLevel = config('errortag-laravel.minimum_error_level', E_ALL);
            if (! ($severity & $minLevel)) {
                return false;
            }

            self::$capturing = true;
            self::$capturing = true;

            try {
                $errorTag = $this->app->make(ErrorTag::class);
            try {
                $errorTag = $this->app->make(ErrorTag::class);

                // Create an exception from the PHP error
                $exception = new \ErrorException($message, 0, $severity, $file, $line);
                // Create an exception from the PHP error
                $exception = new \ErrorException($message, 0, $severity, $file, $line);

                $payload = $errorTag->captureException($exception);
                $payload = $errorTag->captureException($exception);

                $this->sendError($payload);
            } catch (Throwable $e) {
                // Don't break the app if ErrorTag fails
                Log::error('ErrorTag error handler failed', ['error' => $e->getMessage()]);
            } finally {
                self::$capturing = false;
            }
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
            // Let PHP handle the error normally as well
            return false;
        });
    }

    protected function registerShutdownHandler(): void
    {
        // Capture config values NOW, while the app is still healthy.
        // During a fatal/parse error the container may be broken.
        $apiKey = config('errortag-laravel.api_key', '');
        $endpoint = config('errortag-laravel.api_endpoint', 'https://errortag.dev/api/errors');
        $environment = config('app.env', 'production');
        $appName = config('app.name', '');
        $appUrl = config('app.url', '');
        $basePath = base_path().'/';

        register_shutdown_function(function () use ($apiKey, $endpoint, $environment, $appName, $appUrl, $basePath) {
            // Flush any deferred payloads that were captured before the terminating()
            // hook ran — this is the safety net for boot-time exceptions (e.g. ParseError
            // in routes/web.php) where the boot cycle was interrupted.
            try {
                $this->flushPendingErrors();
            } catch (Throwable) {
                // Container may be broken — we'll fall through to the raw path below.
            }

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

            // First try the normal path through the container (works for runtime fatal errors).
            try {
                $errorTag = $this->app->make(ErrorTag::class);

                $exception = new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );

                $payload = $errorTag->captureException($exception);
                $payload = $errorTag->captureException($exception);

                if ($payload) {
                    $apiClient = $this->app->make(ErrorTagApiClient::class);
                    $sent = $apiClient->sendWithTimeout($payload, 5);

                    if ($sent) {
                        return; // Sent successfully, we're done.
                    }
                    // sendWithTimeout returned false (network/timeout) — fall through to raw curl.
                }
            } catch (Throwable) {
                // Container is broken (e.g. ParseError during boot). Fall through to raw send.
            }

            // Fallback: build a minimal payload without the container and send via raw curl.
            // This handles ParseErrors that break the app before the container is fully built.
            if (! $apiKey || ! $endpoint || ! function_exists('curl_init')) {
                return;
            }

            try {
                $file = $error['file'];
                $strippedFile = str_starts_with($file, $basePath)
                  ? substr($file, strlen($basePath))
                  : $file;

                $errorTypeMap = [
                    E_ERROR => 'E_ERROR',
                    E_PARSE => 'ParseError',
                    E_CORE_ERROR => 'E_CORE_ERROR',
                    E_CORE_WARNING => 'E_CORE_WARNING',
                    E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                    E_COMPILE_WARNING => 'E_COMPILE_WARNING',
                    E_USER_ERROR => 'E_USER_ERROR',
                ];

                $exceptionType = $errorTypeMap[$error['type']] ?? 'FatalError';

                // Read source lines around the error if possible
                $sourceLines = [];
                if (is_readable($file) && filesize($file) < 1048576) {
                    $lines = @file($file);
                    if ($lines !== false) {
                        $errorLine = $error['line'];
                        $start = max(1, $errorLine - 15);
                        $end = min(count($lines), $errorLine + 15);
                        for ($i = $start; $i <= $end; $i++) {
                            $sourceLines[] = [
                                'number' => $i,
                                'content' => rtrim($lines[$i - 1]),
                                'is_error_line' => $i === $errorLine,
                            ];
                        }
                    }
                }

                $fingerprint = hash('sha256', $exceptionType.'|'.$file.'|'.$error['line']);

                $payload = [
                    'fingerprint' => $fingerprint,
                    'exception' => [
                        'message' => $error['message'],
                        'type' => $exceptionType,
                        'file' => $strippedFile,
                        'line' => $error['line'],
                        'stack_trace' => [
                            [
                                'file' => $strippedFile,
                                'line' => $error['line'],
                                'function' => '{main}',
                                'source_lines' => $sourceLines,
                            ],
                        ],
                        'code' => null,
                        'source_lines' => $sourceLines,
                    ],
                    'request' => null,
                    'user' => null,
                    'application' => array_filter([
                        'laravel_version' => \Illuminate\Foundation\Application::VERSION,
                        'php_version' => PHP_VERSION,
                        'environment' => $environment,
                        'server_name' => gethostname() ?: 'unknown',
                        'app_name' => $appName,
                        'app_url' => $appUrl,
                    ]),
                    'custom_context' => [],
                    'release' => null,
                    'timestamp' => date('c'),
                ];

                $json = json_encode($payload);

                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => [
                        'X-ErrorTag-Key: '.$apiKey,
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'User-Agent: ErrorTag-Laravel/1.0',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (Throwable) {
                // Nothing we can do — we're in a fatal error shutdown.
            }
        });
    }
}
