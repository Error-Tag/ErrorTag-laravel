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
    $fingerprint = $this->errorPayload['fingerprint'] ?? 'unknown';

    // Check if the error has been fixed before sending
    if ($this->errorHasBeenFixed()) {
      \Illuminate\Support\Facades\Log::info('Error has been fixed - skipping ErrorTag report', [
        'fingerprint' => $fingerprint,
        'file' => $this->errorPayload['exception']['file'] ?? 'unknown',
        'line' => $this->errorPayload['exception']['line'] ?? 'unknown',
      ]);
      return;
    }

    // Circuit breaker: check if we should skip this error due to repeated failures
    if ($this->shouldSkipDueToCircuitBreaker($fingerprint)) {
      \Illuminate\Support\Facades\Log::warning('Circuit breaker triggered - skipping ErrorTag report', [
        'fingerprint' => $fingerprint,
        'message' => $this->errorPayload['exception']['message'] ?? 'unknown',
      ]);
      return;
    }

    // Skip if this error is from the error tracking system itself to prevent infinite loops
    $errorFile = $this->errorPayload['exception']['file'] ?? '';
    if ($this->isErrorTrackingSystemFile($errorFile)) {
      \Illuminate\Support\Facades\Log::info('Skipping ErrorTag report for error tracking system file', [
        'file' => $errorFile,
        'message' => $this->errorPayload['exception']['message'] ?? 'unknown',
      ]);
      return;
    }

    try {
      $payload = $this->reconstructPayload();
      $client->send($payload);

      // Success! Reset the circuit breaker for this error
      $this->resetCircuitBreaker($fingerprint);
    } catch (\Throwable $e) {
      // Increment failure count for circuit breaker
      $this->recordFailure($fingerprint);

      // Log the failure to reconstruct/send payload
      \Illuminate\Support\Facades\Log::error('ErrorTag job failed', [
        'error' => $e->getMessage(),
        'payload_fingerprint' => $fingerprint,
        'failure_count' => $this->getFailureCount($fingerprint),
      ]);

      // Don't rethrow - we don't want to fill the failed jobs table
      // The error is already logged
    }
  }

  /**
   * Check if the error has been fixed by validating the error file.
   * This prevents sending errors that have already been resolved.
   */
  protected function errorHasBeenFixed(): bool
  {
    $errorFile = $this->errorPayload['exception']['file'] ?? '';
    $errorType = $this->errorPayload['exception']['type'] ?? '';

    // If file doesn't exist anymore, error is likely fixed (file deleted/moved)
    if (!empty($errorFile) && !file_exists($errorFile)) {
      return true;
    }

    // For parse errors, check if the file has valid PHP syntax now
    if ($errorType === 'ParseError' && !empty($errorFile) && file_exists($errorFile)) {
      $output = [];
      $exitCode = 0;
      exec('php -l ' . escapeshellarg($errorFile) . ' 2>&1', $output, $exitCode);

      // Exit code 0 means no syntax errors
      if ($exitCode === 0) {
        return true; // Parse error has been fixed!
      }
    }

    return false;
  }

  /**
   * Check if the error file is part of the error tracking system to prevent infinite loops.
   */
  protected function isErrorTrackingSystemFile(string $filePath): bool
  {
    // Check if error is in the error tracking application itself
    $patterns = [
      '/app/Livewire/ErrorDetail.php',
      '/app/Livewire/RelatedPullRequests.php',
      '/app/Models/Error.php',
      '/app/Models/ErrorOccurrence.php',
      '/app/Services/GitHubService.php',
      '/resources/views/livewire/error-detail.blade.php',
    ];

    foreach ($patterns as $pattern) {
      if (str_contains($filePath, $pattern)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Check if the circuit breaker should prevent this error from being reported.
   * Circuit breaker triggers after 5 consecutive failures within 1 hour.
   */
  protected function shouldSkipDueToCircuitBreaker(string $fingerprint): bool
  {
    $failureCount = $this->getFailureCount($fingerprint);
    $threshold = config('errortag-laravel.circuit_breaker_threshold', 5);

    return $failureCount >= $threshold;
  }

  /**
   * Get the failure count for a specific error fingerprint.
   */
  protected function getFailureCount(string $fingerprint): int
  {
    $cacheKey = "errortag:circuit_breaker:{$fingerprint}";
    return \Illuminate\Support\Facades\Cache::get($cacheKey, 0);
  }

  /**
   * Record a failure for circuit breaker tracking.
   */
  protected function recordFailure(string $fingerprint): void
  {
    $cacheKey = "errortag:circuit_breaker:{$fingerprint}";
    $ttl = config('errortag-laravel.circuit_breaker_ttl', 3600); // 1 hour default

    $count = \Illuminate\Support\Facades\Cache::get($cacheKey, 0);
    \Illuminate\Support\Facades\Cache::put($cacheKey, $count + 1, $ttl);
  }

  /**
   * Reset the circuit breaker for a specific error (called on success).
   */
  protected function resetCircuitBreaker(string $fingerprint): void
  {
    $cacheKey = "errortag:circuit_breaker:{$fingerprint}";
    \Illuminate\Support\Facades\Cache::forget($cacheKey);
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
