<?php

namespace App\Enum;

enum MaterielStatut: string
{
  case DISPONIBLE = 'disponible';
  case EN_UTILISATION = 'en_utilisation';
  case EN_MAINTENANCE = 'en_maintenance';
  case EN_PANNE = 'en_panne';
  case HORS_SERVICE = 'hors_service';
  case ARCHIVE = 'archive';

  public function label(): string
  {
    return match ($this) {
      self::DISPONIBLE => 'Disponible',
      self::EN_UTILISATION => 'En utilisation',
      self::EN_MAINTENANCE => 'En maintenance',
      self::EN_PANNE => 'En panne',
      self::HORS_SERVICE => 'Hors service',
      self::ARCHIVE => 'Archivé',
    };
  }

  public function badgeClass(): string
  {
    return match ($this) {
      self::DISPONIBLE => 'bg-success-subtle text-success border border-success-subtle',
      self::EN_UTILISATION => 'bg-primary-subtle text-primary border border-primary-subtle',
      self::EN_MAINTENANCE => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
      self::EN_PANNE => 'bg-danger-subtle text-danger border border-danger-subtle',
      self::HORS_SERVICE => 'bg-dark-subtle text-dark border border-dark-subtle',
      self::ARCHIVE => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
    };
  }
}
