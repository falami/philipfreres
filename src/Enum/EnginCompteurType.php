<?php

namespace App\Enum;

enum EnginCompteurType: string
{
  case HEURE = 'heure';
  case KILOMETRE = 'kilometre';

  public function label(): string
  {
    return match ($this) {
      self::HEURE => 'Heures',
      self::KILOMETRE => 'Kilomètres',
    };
  }

  public function unite(): string
  {
    return match ($this) {
      self::HEURE => 'h',
      self::KILOMETRE => 'km',
    };
  }

  public function icon(): string
  {
    return match ($this) {
      self::HEURE => 'bi-clock-history',
      self::KILOMETRE => 'bi-speedometer2',
    };
  }
}
