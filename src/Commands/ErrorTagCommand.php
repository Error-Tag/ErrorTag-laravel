<?php

namespace ErrorTag\ErrorTag\Commands;

use ErrorTag\ErrorTag\ErrorTag;
use ErrorTag\ErrorTag\Http\ErrorTagApiClient;
use Illuminate\Console\Command;

class ErrorTagCommand extends Command
{
  public $signature = 'errortag:test {--send-test-error : Send a test error to the dashboard}';

  public $description = 'Test your ErrorTag configuration and connection';

  public function handle(ErrorTagApiClient $client, ErrorTag $errorTag): int
  {
    $this->info('Testing ErrorTag Configuration...');
    $this->newLine();

    // 1. Enabled?
    if (! config('errortag-laravel.enabled', true)) {
      $this->error('✗ ErrorTag is disabled — set ERRORTAG_ENABLED=true');

      return self::FAILURE;
    }
    $this->info('✓ Enabled');

    // 2. API key?
    $apiKey = config('errortag-laravel.api_key');
    if (! $apiKey) {
      $this->error('✗ No API key — set ERRORTAG_KEY in .env');

      return self::FAILURE;
    }
    $this->info('✓ API key:     ' . substr($apiKey, 0, 12) . '...');

    // 3. Endpoint + environment
    $endpoint = config('errortag-laravel.api_endpoint');
    $environment = config('errortag-laravel.environment');
    $this->info('✓ Endpoint:    ' . $endpoint);
    $this->info('✓ Environment: ' . $environment);
    $this->info('✓ Queue:       ' . (config('errortag-laravel.use_queue') ? 'yes' : 'no (deferred after response)'));

    $ignoredUrls = config('errortag-laravel.ignored_urls', []);
    if (! empty($ignoredUrls)) {
      $this->info('✓ Ignored URLs: ' . implode(', ', $ignoredUrls));
    }

    // 4. Health check
    $this->newLine();
    $this->info('Checking API connectivity...');
    if (! $client->testConnection()) {
      $this->error('✗ Cannot reach ' . $endpoint);
      $this->line('   Check your ERRORTAG_ENDPOINT and that the dashboard is running.');

      return self::FAILURE;
    }
    $this->info('✓ Dashboard is reachable');

    // 5. Optionally send a real test error
    if ($this->option('send-test-error')) {
      $this->newLine();
      $this->info('Sending test error...');

      try {
        $testException = new \RuntimeException(
          'ErrorTag test error — sent via php artisan errortag:test --send-test-error'
        );

        $payload = $errorTag->captureException($testException);

        if (! $payload) {
          $this->warn('⚠  captureException() returned null.');
          $this->line('   Possible reasons:');
          $this->line('     - sample_rate < 1.0');
          $this->line('     - exception class is in ignored_exceptions');
          $this->line('     - current path matches ignored_urls');

          return self::FAILURE;
        }

        // Send directly so we get immediate feedback (bypasses terminating hook)
        $timeout = config('errortag-laravel.sync_timeout', 5);
        $success = $client->sendWithTimeout($payload, $timeout);

        if ($success) {
          $this->info('✓ Test error delivered to dashboard!');
          $this->line('   Fingerprint: ' . $payload->fingerprint);
          $this->line('   Check the dashboard now — it should appear immediately.');
        } else {
          $this->error('✗ Dashboard returned a non-2xx response.');
          $this->line('   Check the dashboard logs for details.');

          return self::FAILURE;
        }
      } catch (\Throwable $e) {
        $this->error('✗ Exception while sending: ' . $e->getMessage());

        return self::FAILURE;
      }
    }

    $this->newLine();
    $this->info('🎉 All checks passed!');
    if (! $this->option('send-test-error')) {
      $this->comment('   Run with --send-test-error to send a real test error:');
      $this->comment('   php artisan errortag:test --send-test-error');
    }

    return self::SUCCESS;
  }
}
