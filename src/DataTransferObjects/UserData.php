<?php

namespace ErrorTag\ErrorTag\DataTransferObjects;

class UserData
{
  public function __construct(
    public readonly string|int $id,
    public readonly ?string $email = null,
    public readonly ?string $name = null,
    public readonly ?string $avatar = null,
    public readonly array $attributes = [],
  ) {}

  public function toArray(): array
  {
    return array_filter([
      'id' => $this->id,
      'email' => $this->email,
      'name' => $this->name,
      'avatar' => $this->avatar,
      'attributes' => $this->attributes,
    ], fn($value) => $value !== null && $value !== []);
  }
}
