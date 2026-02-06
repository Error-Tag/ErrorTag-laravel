<?php

namespace ErrorTag\ErrorTag\Support;

use Throwable;

class FingerprintGenerator
{
    /**
     * Generate a unique fingerprint for error grouping.
     * Errors with the same fingerprint will be grouped together in the ErrorTag dashboard.
     */
    public static function generate(Throwable $exception): string
    {
        $components = [
            get_class($exception),
            $exception->getFile(),
            $exception->getLine(),
        ];

        return hash('sha256', implode('|', $components));
    }
}
