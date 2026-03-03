<?php

namespace ErrorTag\ErrorTag\Support;

use ErrorTag\ErrorTag\DataTransferObjects\ExceptionData;
use Throwable;

class FingerprintGenerator
{
    /**
     * Generate a unique fingerprint for error grouping.
     * Errors with the same fingerprint will be grouped together in the ErrorTag dashboard.
     */
    public static function generate(Throwable $exception): string
    {
        $originFile = $exception->getFile();
        $originLine = $exception->getLine();

        // Keep fingerprints stable across servers by normalizing file paths the same
        // way we store them in the payload (strip base path, resolve compiled views).
        [$resolvedFile, $resolvedLine] = ExceptionData::resolveCompiledView($originFile, $originLine);
        $normalizedFile = ExceptionData::stripBasePath($resolvedFile) ?? $resolvedFile ?? 'unknown';

        $components = [
            get_class($exception),
            $normalizedFile,
            $resolvedLine ?? $originLine,
        ];

        return hash('sha256', implode('|', $components));
    }
}
