<?php

use ErrorTag\ErrorTag\Collectors\ApplicationContextCollector;
use ErrorTag\ErrorTag\Collectors\RequestContextCollector;
use ErrorTag\ErrorTag\Collectors\UserContextCollector;
use ErrorTag\ErrorTag\ErrorTag;

beforeEach(function () {
    config(['errortag-laravel.enabled' => true]);
    config(['errortag-laravel.api_key' => 'test_key_12345']);
    config(['errortag-laravel.sample_rate' => 1.0]);
    config(['errortag-laravel.ignored_exceptions' => []]);
});

it('captures exception and creates payload', function () {
    $errorTag = new ErrorTag(
        new ApplicationContextCollector,
        new RequestContextCollector,
        new UserContextCollector
    );

    $exception = new Exception('Test exception');
    $payload = $errorTag->captureException($exception);

    expect($payload)->not->toBeNull()
        ->and($payload->exception->message)->toBe('Test exception')
        ->and($payload->fingerprint)->toBeString();
});

it('adds custom context to payload', function () {
    $errorTag = new ErrorTag(
        new ApplicationContextCollector,
        new RequestContextCollector,
        new UserContextCollector
    );

    $errorTag->context(['order_id' => 123, 'user_type' => 'premium']);

    $exception = new Exception('Test');
    $payload = $errorTag->captureException($exception);

    expect($payload->customContext)->toBe(['order_id' => 123, 'user_type' => 'premium']);
});

it('clears context after capturing', function () {
    $errorTag = new ErrorTag(
        new ApplicationContextCollector,
        new RequestContextCollector,
        new UserContextCollector
    );

    $errorTag->context(['test' => 'value']);
    $errorTag->captureException(new Exception('First'));

    $payload = $errorTag->captureException(new Exception('Second'));

    expect($payload->customContext)->toBe([]);
});

it('does not capture when disabled', function () {
    config(['errortag-laravel.enabled' => false]);

    $errorTag = new ErrorTag(
        new ApplicationContextCollector,
        new RequestContextCollector,
        new UserContextCollector
    );

    $payload = $errorTag->captureException(new Exception('Test'));

    expect($payload)->toBeNull();
});

it('does not capture when api key is missing', function () {
    config(['errortag-laravel.api_key' => null]);

    $errorTag = new ErrorTag(
        new ApplicationContextCollector,
        new RequestContextCollector,
        new UserContextCollector
    );

    $payload = $errorTag->captureException(new Exception('Test'));

    expect($payload)->toBeNull();
});

it('does not capture ignored exceptions', function () {
    config(['errortag-laravel.ignored_exceptions' => [RuntimeException::class]]);

    $errorTag = new ErrorTag(
        new ApplicationContextCollector,
        new RequestContextCollector,
        new UserContextCollector
    );

    $payload = $errorTag->captureException(new RuntimeException('Ignored'));

    expect($payload)->toBeNull();
});

it('respects sample rate', function () {
    config(['errortag-laravel.sample_rate' => 0.0]);

    $errorTag = new ErrorTag(
        new ApplicationContextCollector,
        new RequestContextCollector,
        new UserContextCollector
    );

    $payload = $errorTag->captureException(new Exception('Test'));

    expect($payload)->toBeNull();
});
