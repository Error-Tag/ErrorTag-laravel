<?php

namespace ErrorTag\ErrorTag\Collectors;

use ErrorTag\ErrorTag\DataTransferObjects\UserData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

class UserContextCollector
{
  public function __construct(
    protected bool $captureUser = true,
  ) {}

  public function collect(): ?UserData
  {
    if (! $this->captureUser) {
      return null;
    }

    $user = Auth::user();

    if (! $user) {
      return null;
    }

    return new UserData(
      id: $user->getAuthIdentifier(),
      email: $this->getUserEmail($user),
      name: $this->getUserName($user),
      attributes: $this->getUserAttributes($user),
    );
  }

  protected function getUserEmail(Authenticatable $user): ?string
  {
    // @phpstan-ignore-next-line
    if (method_exists($user, 'getEmail')) {
      return $user->getEmail(); // @phpstan-ignore-line
    }

    // @phpstan-ignore-next-line
    if (property_exists($user, 'email')) {
      return $user->email; // @phpstan-ignore-line
    }

    return null;
  }

  protected function getUserName(Authenticatable $user): ?string
  {
    // @phpstan-ignore-next-line
    if (method_exists($user, 'getName')) {
      return $user->getName(); // @phpstan-ignore-line
    }

    // @phpstan-ignore-next-line
    if (property_exists($user, 'name')) {
      return $user->name; // @phpstan-ignore-line
    }

    return null;
  }

  protected function getUserAttributes(Authenticatable $user): array
  {
    $attributes = [];

    // Try to get role if available
    // @phpstan-ignore-next-line
    if (method_exists($user, 'getRoles')) {
      $attributes['roles'] = $user->getRoles(); // @phpstan-ignore-line
      // @phpstan-ignore-next-line
    } elseif (property_exists($user, 'role')) {
      $attributes['role'] = $user->role; // @phpstan-ignore-line
    }

    return $attributes;
  }
}
