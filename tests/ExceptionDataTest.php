<?php

use ErrorTag\ErrorTag\DataTransferObjects\ExceptionData;

it('creates exception data from throwable', function () {
    $exception = new Exception('Test exception message', 500);

    $exceptionData = ExceptionData::fromThrowable($exception);

    expect($exceptionData->message)->toBe('Test exception message')
        ->and($exceptionData->type)->toBe(Exception::class)
        ->and($exceptionData->code)->toBe('500')
        ->and($exceptionData->stackTrace)->toBeArray()
        ->and($exceptionData->file)->toContain('ExceptionDataTest.php');
});

it('formats stack trace correctly', function () {
    $exception = new Exception('Test');

    $exceptionData = ExceptionData::fromThrowable($exception, maxDepth: 5);

    expect($exceptionData->stackTrace)->toHaveCount(5)
        ->and($exceptionData->stackTrace[0])->toHaveKeys(['file', 'line', 'function']);
});

it('sanitizes stack trace arguments when enabled', function () {
    $exception = new Exception('Test');

    $exceptionData = ExceptionData::fromThrowable($exception, captureArgs: true);

    expect($exceptionData->stackTrace)->toBeArray();
});

it('converts to array correctly', function () {
    $exception = new Exception('Test', 404);

    $exceptionData = ExceptionData::fromThrowable($exception);
    $array = $exceptionData->toArray();

    expect($array)->toHaveKeys(['message', 'type', 'file', 'line', 'stack_trace', 'code'])
        ->and($array['message'])->toBe('Test')
        ->and($array['code'])->toBe('404');
});
