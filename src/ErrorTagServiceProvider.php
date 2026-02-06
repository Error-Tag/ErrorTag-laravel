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
    }
  }

  protected function registerExceptionHandler(): void
  {
    /** @var \Illuminate\Foundation\Exceptions\Handler $handler */
    $handler = $this->app->make(ExceptionHandler::class);

    $handler->reportable(function (Throwable $e) { // @phpstan-ignore-line
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
      }
    });
  }
}
