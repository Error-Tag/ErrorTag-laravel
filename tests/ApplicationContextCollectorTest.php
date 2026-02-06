<?php

use ErrorTag\ErrorTag\Collectors\ApplicationContextCollector;
use Illuminate\Foundation\Application;

beforeEach(function () {
  config(['errortag-laravel.environment' => 'testing']);
  config(['errortag-laravel.server_name' => 'test-server']);
  config(['app.name' => 'ErrorTag Test App']);
});

it('collects application context', function () {
  $collector = new ApplicationContextCollector();

  $context = $collector->collect();

  expect($context->laravelVersion)->toBe(Application::VERSION)
    ->and($context->phpVersion)->toBe(PHP_VERSION)
    ->and($context->environment)->toBe('testing')
    ->and($context->serverName)->toBe('test-server')
    ->and($context->appName)->toBe('ErrorTag Test App');
});

it('converts to array correctly', function () {
  $collector = new ApplicationContextCollector();

  $array = $collector->collect()->toArray();

  expect($array)->toHaveKeys(['laravel_version', 'php_version', 'environment', 'server_name', 'app_name']);
});
