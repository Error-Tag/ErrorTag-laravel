<?php

namespace ErrorTag\ErrorTag\Commands;

use ErrorTag\ErrorTag\ErrorTag;
use ErrorTag\ErrorTag\Http\ErrorTagApiClient;
use ErrorTag\ErrorTag\Jobs\SendErrorToErrorTagJob;
use Illuminate\Console\Command;

class ErrorTagCommand extends Command
{
    public $signature = 'errortag:test {--send-test-error : Send a test error to ErrorTag}';

    public $description = 'Test your ErrorTag configuration and connection';

    public function handle(ErrorTagApiClient $client, ErrorTag $errorTag): int
    {
        $this->info('🔍 Testing ErrorTag Configuration...');
        $this->newLine();

        // Check if ErrorTag is enabled
        if (! config('errortag-laravel.enabled', true)) {
            $this->warn('⚠️  ErrorTag is currently disabled');
            $this->info('   Set ERRORTAG_ENABLED=true in your .env file to enable it');

            return self::FAILURE;
        }

        $this->info('✓ ErrorTag is enabled');

        // Check if API key is configured
        $apiKey = config('errortag-laravel.api_key');
        if (! $apiKey) {
            $this->error('✗ No API key configured');
            $this->info('   Set ERRORTAG_KEY in your .env file');

            return self::FAILURE;
        }

        $this->info('✓ API key is configured: '.substr($apiKey, 0, 10).'...');

        // Check environment
        $environment = config('errortag-laravel.environment');
        $this->info('✓ Environment: '.$environment);

        // Check endpoint
        $endpoint = config('errortag-laravel.api_endpoint');
        $this->info('✓ API Endpoint: '.$endpoint);

        // Test connection
        $this->newLine();
        $this->info('🌐 Testing connection to ErrorTag API...');

        if ($client->testConnection()) {
            $this->info('✓ Successfully connected to ErrorTag API');
        } else {
            $this->error('✗ Failed to connect to ErrorTag API');
            $this->info('   Please check your internet connection and API endpoint');

            return self::FAILURE;
        }

        // Send test error if requested
        if ($this->option('send-test-error')) {
            $this->newLine();
            $this->info('📤 Sending test error...');

            try {
                $testException = new \Exception('This is a test error from ErrorTag Laravel package');
                $payload = $errorTag->captureException($testException);

                if ($payload) {
                    SendErrorToErrorTagJob::dispatch($payload->toArray());
                    $this->info('✓ Test error queued successfully');
                    $this->info('   Check your ErrorTag dashboard in a few moments');
                } else {
                    $this->warn('⚠️  Test error was not captured (check your sample rate and ignored exceptions)');
                }
            } catch (\Throwable $e) {
                $this->error('✗ Failed to send test error: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('🎉 All tests passed! ErrorTag is ready to use.');
        $this->newLine();
        $this->comment('Try running: php artisan errortag:test --send-test-error');

        return self::SUCCESS;
    }
}
