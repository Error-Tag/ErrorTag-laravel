<?php

namespace ErrorTag\ErrorTag\DataTransferObjects;

class ErrorPayload
{
  public function __construct(
    public readonly string $fingerprint,
    public readonly ExceptionData $exception,
    public readonly ?RequestData $request,
    public readonly ?UserData $user,
    public readonly ApplicationData $application,
    public readonly array $customContext,
    public readonly ?string $release,
    public readonly string $timestamp,
  ) {}

  public function toArray(): array
  {
    return [
      'fingerprint' => $this->fingerprint,
      'exception' => $this->exception->toArray(),
      'request' => $this->request?->toArray(),
      'user' => $this->user?->toArray(),
      'application' => $this->application->toArray(),
      'custom_context' => $this->customContext,
      'release' => $this->release,
      'timestamp' => $this->timestamp,
    ];
  }
}
