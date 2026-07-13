<?php

namespace ErrorTag\ErrorTag\DataTransferObjects;

use Throwable;

class ExceptionData
{
    public function __construct(
        public readonly string $message,
        public readonly string $type,
        public readonly string $file,
        public readonly int $line,
        public readonly array $stackTrace,
        public readonly ?string $code = null,
        public readonly array $sourceLines = [],
    ) {}

    public static function fromThrowable(Throwable $exception, int $maxDepth = 50, bool $captureArgs = false): self
    {
        $originFile = $exception->getFile();
        $originLine = $exception->getLine();

        // Resolve compiled Blade view paths back to their source .blade.php files.
        [$resolvedFile, $resolvedLine] = self::resolveCompiledView($originFile, $originLine);

        return new self(
            message: $exception->getMessage(),
            type: get_class($exception),
            file: self::stripBasePath($resolvedFile),
            line: $resolvedLine,
            stackTrace: self::formatStackTrace($exception, $maxDepth, $captureArgs),
            code: $exception->getCode() ? (string) $exception->getCode() : null,
            sourceLines: self::readSourceLines($resolvedFile, $resolvedLine),
        );
    }

    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'type' => $this->type,
            'file' => $this->file,
            'line' => $this->line,
            'stack_trace' => $this->stackTrace,
            'code' => $this->code,
            'source_lines' => $this->sourceLines,
        ];
    }

    /**
     * If $filePath points to a compiled Blade view (storage/framework/views/*.php),
     * resolve it back to the original .blade.php source file and map the compiled
     * line number back to the nearest corresponding source line.
     *
     * Returns [$resolvedAbsolutePath, $resolvedLine]. Falls back to the original
     * values when resolution is not possible.
     */

    /**
     * Extract the original .blade.php source path from a compiled view file.
     *
     * Modern Laravel (5.6+) writes a footer at the end of every compiled view:
     *   [php-open-tag] /**PATH /absolute/path/to/view.blade.php ENDPATH** /
     *
     * Older versions wrote the path in a header comment at the beginning.
     * We try the tail first (more reliable), then fall back to scanning the head.
     */
    protected static function extractBladeSourcePath(string $filePath): ?string
    {
        $size = @filesize($filePath);
        if ($size === false) {
            return null;
        }

        // ── Try modern footer (/**PATH ... ENDPATH**/) ──────────────────────────
        // The marker is always near the very end of the file; read last 512 bytes.
        $tailLength = min($size, 512);
        $fh = @fopen($filePath, 'r');
        if (! $fh) {
            return null;
        }
        fseek($fh, -$tailLength, SEEK_END);
        $tail = fread($fh, $tailLength);

        if ($tail !== false && preg_match('#/\*\*PATH\s+(.+?\.blade\.php)\s+ENDPATH\*\*/#', $tail, $m)) {
            fclose($fh);

            return $m[1];
        }

        // ── Fallback: scan the first 4 KB for older header formats ─────────────
        fseek($fh, 0);
        $head = fread($fh, 4096);
        fclose($fh);

        if ($head === false) {
            return null;
        }

        // "Compiled from: /path/to/view.blade.php"
        if (preg_match('#(?:Compiled|compiled)\s+from[:\s]+["\']?([^\s"\'*\r\n]+\.blade\.php)#', $head, $m)) {
            return $m[1];
        }

        // Any absolute .blade.php path mentioned in the head block
        if (preg_match('#([/\\\\\w.\-]+\.blade\.php)#', $head, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function resolveCompiledView(?string $filePath, ?int $line): array
    {
        if (! $filePath || ! $line) {
            return [$filePath, $line];
        }

        // Compiled views live in storage/framework/views/ and end in .php (not .blade.php)
        $normalised = str_replace('\\', '/', $filePath);
        if (! preg_match('#/storage/framework/views/[^/]+\.php$#', $normalised)) {
            return [$filePath, $line];
        }

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return [$filePath, $line];
        }

        $sourcePath = self::extractBladeSourcePath($filePath);

        if (! $sourcePath || ! file_exists($sourcePath) || ! is_readable($sourcePath)) {
            return [$filePath, $line];
        }

        // Map the compiled line number back to the Blade source line.
        // Laravel's BladeCompiler injects comments like: /* line N */  or  // line N
        // Walk backwards from $line to find the nearest annotation.
        $compiledLines = @file($filePath, FILE_IGNORE_NEW_LINES);
        if ($compiledLines === false) {
            return [$sourcePath, $line];
        }

        $sourceLine = null;
        $limit = min($line - 1, count($compiledLines) - 1);
        for ($i = $limit; $i >= 0; $i--) {
            if (preg_match('#/\*\*?\s*(?:line|LINE|@line)\s+(\d+)\s*\*/#', $compiledLines[$i], $m)) {
                $sourceLine = (int) $m[1];
                break;
            }
            if (preg_match('#//\s*(?:line|LINE)\s+(\d+)#', $compiledLines[$i], $m)) {
                $sourceLine = (int) $m[1];
                break;
            }
        }

        return [$sourcePath, $sourceLine ?? $line];
    }

    /**
     * Strip the application base path from an absolute file path so only the
     * app-relative portion is stored (e.g. "app/Http/Controllers/Foo.php").
     * This prevents leaking server directory structure and usernames in payloads.
     */
    public static function stripBasePath(?string $filePath): ?string
    {
        if (! $filePath) {
            return $filePath;
        }

        $base = rtrim(base_path(), '/').'/';

        if (str_starts_with($filePath, $base)) {
            return substr($filePath, strlen($base));
        }

        // Fallback: strip everything before the first known Laravel project directory.
        // Handles any deployment depth: /home/user/sites/project/app/... -> app/...
        if (preg_match('#^.+?/(app|bootstrap|config|database|resources|routes|storage|tests|vendor)/#', $filePath, $m, PREG_OFFSET_CAPTURE)) {
            return substr($filePath, $m[1][1]);
        }

        return $filePath;
    }

    /**
     * Returns true for frame files that belong to vendor code or compiled views.
     * Mirrors the dashboard's ErrorDetail::isNonAppFrame() check.
     */
    protected static function isVendorOrCompiledFrame(?string $file): bool
    {
        if (! $file) {
            return true;
        }

        $normalised = str_replace('\\', '/', $file);

        return str_contains($normalised, '/vendor/')
          || str_contains($normalised, 'storage/framework/views/');
    }

    protected static function formatStackTrace(Throwable $exception, int $maxDepth, bool $captureArgs): array
    {
        $trace = $exception->getTrace();

        // Slice to maxDepth, but if the slice contains no app frames at all,
        // scan beyond maxDepth to include the first app frame. This ensures that
        // for vendor-heavy exceptions (e.g. QueryException from deep DB internals)
        // the calling migration / controller is always visible in the dashboard.
        $slice = array_slice($trace, 0, $maxDepth);
        $hasAppFrame = false;
        foreach ($slice as $f) {
            if (! self::isVendorOrCompiledFrame($f['file'] ?? null)) {
                $hasAppFrame = true;
                break;
            }
        }
        if (! $hasAppFrame) {
            foreach (array_slice($trace, $maxDepth) as $f) {
                if (! self::isVendorOrCompiledFrame($f['file'] ?? null)) {
                    $slice[] = $f;
                    break;
                }
            }
        }

        $formattedTrace = [];

        foreach ($slice as $frame) {
            $absoluteFile = $frame['file'] ?? null;
            $frameLine = $frame['line'] ?? null;

            // Resolve compiled Blade view paths back to their source .blade.php files.
            [$resolvedFile, $resolvedLine] = self::resolveCompiledView($absoluteFile, $frameLine);

            $formattedFrame = [
                'file' => self::stripBasePath($resolvedFile),
                'line' => $resolvedLine,
                'function' => $frame['function'] ?? '{closure}',
            ];

            if (isset($frame['class'])) {
                $formattedFrame['class'] = $frame['class'];
                $formattedFrame['type'] = $frame['type'] ?? '::';
            }

            if ($captureArgs && isset($frame['args'])) {
                $formattedFrame['args'] = self::sanitizeArgs($frame['args']);
            }

            // Capture source lines using the resolved absolute path.
            $formattedFrame['source_lines'] = self::readSourceLines(
                $resolvedFile ?? '',
                $resolvedLine ?? 0,
            );

            $formattedTrace[] = $formattedFrame;
        }

        return $formattedTrace;
    }

    /**
     * Read up to 15 lines of context around $errorLine from a file on disk.
     * Returns an empty array if the file cannot be read (vendor phars, etc.).
     *
     * @return array<int, array{number: int, content: string, is_error_line: bool}>
     */
    protected static function readSourceLines(string $filePath, int $errorLine, int $context = 15): array
    {
        if (! $filePath || $filePath === 'unknown' || ! file_exists($filePath) || ! is_readable($filePath)) {
            return [];
        }

        if (filesize($filePath) > 1_000_000) {
            return [];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $total = count($lines);

        // Clamp the target line to the file length.
        // This handles cases where a compiled Blade view line number exceeds the
        // source .blade.php file length — the mapping is approximate and the
        // compiled line may be larger than the number of Blade source lines.
        $clampedLine = max(1, min($errorLine, $total));

        $start = max(0, $clampedLine - $context - 1);
        $end = min($total - 1, $clampedLine + $context - 1);
        $result = [];

        for ($i = $start; $i <= $end; $i++) {
            $result[] = [
                'number' => $i + 1,
                'content' => $lines[$i],
                'is_error_line' => ($i + 1) === $errorLine,
            ];
        }

        return $result;
    }

    protected static function sanitizeArgs(array $args): array
    {
        return array_map(function ($arg) {
            if (is_object($arg)) {
                return get_class($arg);
            }
            if (is_array($arg)) {
                return '[Array]';
            }
            if (is_string($arg) && strlen($arg) > 100) {
                return substr($arg, 0, 100).'...';
            }

            return $arg;
        }, $args);
    }
}
