<?php

namespace App\Enum;

enum ExternalProvider: string
{
  case ALX = 'alx';
  case TOTAL = 'total';
  case EDENRED = 'edenred';

  public function label(): string
  {
    return match ($this) {
      self::ALX => 'ALX',
      self::TOTAL => 'TOTAL',
      self::EDENRED => 'EDENRED',
    };
  }

  public function badgeClass(): string
  {
    return match ($this) {
      self::ALX => 'bg-primary',
      self::TOTAL => 'bg-danger',
      self::EDENRED => 'bg-success',
    };
  }

  public function badgeColor(): string
  {
    return match ($this) {
      self::ALX => '#0d6efd',
      self::TOTAL => '#dc3545',
      self::EDENRED => '#198754',
    };
  }
}
