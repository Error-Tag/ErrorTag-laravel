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
  ) {}

  public static function fromThrowable(Throwable $exception, int $maxDepth = 50, bool $captureArgs = false): self
  {
    return new self(
      message: $exception->getMessage(),
      type: get_class($exception),
      file: $exception->getFile(),
      line: $exception->getLine(),
      stackTrace: self::formatStackTrace($exception, $maxDepth, $captureArgs),
      code: $exception->getCode() ? (string) $exception->getCode() : null,
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
    ];
  }

  protected static function formatStackTrace(Throwable $exception, int $maxDepth, bool $captureArgs): array
  {
    $trace = $exception->getTrace();
    $formattedTrace = [];

    foreach (array_slice($trace, 0, $maxDepth) as $index => $frame) {
      $formattedFrame = [
        'file' => $frame['file'] ?? 'unknown',
        'line' => $frame['line'] ?? 0,
        'function' => $frame['function'] ?? 'unknown',
      ];

      if (isset($frame['class'])) {
        $formattedFrame['class'] = $frame['class'];
        $formattedFrame['type'] = $frame['type'] ?? '::';
      }

      if ($captureArgs && isset($frame['args'])) {
        $formattedFrame['args'] = self::sanitizeArgs($frame['args']);
      }

      $formattedTrace[] = $formattedFrame;
    }

    return $formattedTrace;
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
