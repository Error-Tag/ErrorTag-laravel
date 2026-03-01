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

    return new self(
      message: $exception->getMessage(),
      type: get_class($exception),
      file: self::stripBasePath($originFile),
      line: $exception->getLine(),
      stackTrace: self::formatStackTrace($exception, $maxDepth, $captureArgs),
      code: $exception->getCode() ? (string) $exception->getCode() : null,
      sourceLines: self::readSourceLines($originFile, $exception->getLine()),
    );
  }

  /**
   * Strip the application base path from an absolute file path so only the
   * app-relative portion is stored (e.g. "app/Http/Controllers/Foo.php").
   * This prevents leaking server directory structure and usernames in payloads.
   */
  protected static function stripBasePath(?string $filePath): ?string
  {
    if (! $filePath) {
      return $filePath;
    }

    $base = rtrim(base_path(), '/') . '/';

    if (str_starts_with($filePath, $base)) {
      return substr($filePath, strlen($base));
    }

    // Fallback: strip everything up to and including the 3rd path segment
    // for common deployment patterns: /var/www/html/app/... → app/...
    //                                 /home/user/project/app/... → app/...
    //                                 /srv/www/project/app/... → app/...
    if (preg_match('#^(?:/[^/]+){1,3}/(.+)$#', $filePath, $m)) {
      return $m[1];
    }

    return $filePath;
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

  protected static function formatStackTrace(Throwable $exception, int $maxDepth, bool $captureArgs): array
  {
    $trace = $exception->getTrace();
    $formattedTrace = [];

    foreach (array_slice($trace, 0, $maxDepth) as $index => $frame) {
      $absoluteFile = $frame['file'] ?? null;

      $formattedFrame = [
        'file' => self::stripBasePath($absoluteFile),
        'line' => $frame['line'] ?? null,
        'function' => $frame['function'] ?? '{closure}',
      ];

      if (isset($frame['class'])) {
        $formattedFrame['class'] = $frame['class'];
        $formattedFrame['type'] = $frame['type'] ?? '::';
      }

      if ($captureArgs && isset($frame['args'])) {
        $formattedFrame['args'] = self::sanitizeArgs($frame['args']);
      }

      // Capture source lines using the original absolute path so file_exists() works,
      // but we store only the stripped relative path in 'file'.
      $formattedFrame['source_lines'] = self::readSourceLines(
        $absoluteFile ?? '',
        $formattedFrame['line'] ?? 0,
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

    $total  = count($lines);
    $start  = max(0, $errorLine - $context - 1);
    $end    = min($total - 1, $errorLine + $context - 1);
    $result = [];

    for ($i = $start; $i <= $end; $i++) {
      $result[] = [
        'number'        => $i + 1,
        'content'       => $lines[$i],
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
        return substr($arg, 0, 100) . '...';
      }

      return $arg;
    }, $args);
  }
}
