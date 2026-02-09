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
        endpoint: config('errortag-laravel.api_endpoint', 'https://api.errortag.com/api/errors'),
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
    }
  }

  protected function registerExceptionHandler(): void
  {
    /** @var \Illuminate\Foundation\Exceptions\Handler $handler */
    $handler = $this->app->make(ExceptionHandler::class);

    $handler->reportable(function (Throwable $e) { // @phpstan-ignore-line
      // Prevent ErrorTag from capturing its own errors
      if (self::$capturing) {
        return;
      }

      // Don't capture errors from ErrorTag package itself
      $trace = $e->getTrace();
      if (!empty($trace)) {
        $firstFrame = $trace[0] ?? [];
        if (isset($firstFrame['file']) && (str_contains($firstFrame['file'], 'ErrorTag') || str_contains($firstFrame['file'], 'errortag'))) {
          return;
        }
      }

      self::$capturing = true;

      try {
        $errorTag = $this->app->make(ErrorTag::class);
        $payload = $errorTag->captureException($e);

        if ($payload) {
          // Queue the error for async sending
          SendErrorToErrorTagJob::dispatch($payload->toArray());
        }
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
    if (!config('errortag-laravel.capture_php_errors', true)) {
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
      if (!(error_reporting() & $severity)) {
        return false;
      }

      // Check if this error level should be captured
      $minLevel = config('errortag-laravel.minimum_error_level', E_ALL);
      if (!($severity & $minLevel)) {
        return false;
      }

      self::$capturing = true;

      try {
        $errorTag = $this->app->make(ErrorTag::class);

        // Create an exception from the PHP error
        $exception = new \ErrorException($message, 0, $severity, $file, $line);

        $payload = $errorTag->captureException($exception);

        if ($payload) {
          SendErrorToErrorTagJob::dispatch($payload->toArray());
        }
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
      if ($error === null || !in_array($error['type'], [
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
          // For fatal errors, we need to send synchronously since the app is dying
          $apiClient = $this->app->make(ErrorTagApiClient::class);
          $apiClient->send($payload);
        }
      } catch (Throwable $e) {
        // Can't do much here since we're already in a fatal error state
        // Try to log it if possible
        @error_log('ErrorTag shutdown handler failed: ' . $e->getMessage());
      }
    });
  }
}
