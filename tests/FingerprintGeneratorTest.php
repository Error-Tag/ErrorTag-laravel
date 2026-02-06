<?php

use ErrorTag\ErrorTag\Support\FingerprintGenerator;

it('generates consistent fingerprints for same exception', function () {
    $exception = new Exception('Test error');

    $fingerprint1 = FingerprintGenerator::generate($exception);
    $fingerprint2 = FingerprintGenerator::generate($exception);

    expect($fingerprint1)->toBe($fingerprint2);
});

it('generates different fingerprints for different exceptions', function () {
    $exception1 = new Exception('Test error 1');
    $exception2 = new RuntimeException('Test error 2');

    $fingerprint1 = FingerprintGenerator::generate($exception1);
    $fingerprint2 = FingerprintGenerator::generate($exception2);

    expect($fingerprint1)->not->toBe($fingerprint2);
});

it('generates sha256 hash', function () {
    $exception = new Exception('Test');

    $fingerprint = FingerprintGenerator::generate($exception);

    expect($fingerprint)->toBeString()
        ->and(strlen($fingerprint))->toBe(64); // SHA256 produces 64 character hex string
});
