<?php

namespace App\Enum;

enum ChantierStatut: string
{
  case BROUILLON = 'brouillon';
  case EN_COURS = 'en_cours';
  case TERMINE = 'termine';
  case ARCHIVE = 'archive';

  public function label(): string
  {
    return match ($this) {
      self::BROUILLON => 'Brouillon',
      self::EN_COURS => 'En cours',
      self::TERMINE => 'Terminé',
      self::ARCHIVE => 'Archivé',
    };
  }

  public function badgeClass(): string
  {
    return match ($this) {
      self::BROUILLON => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
      self::EN_COURS => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
      self::TERMINE => 'bg-success-subtle text-success border border-success-subtle',
      self::ARCHIVE => 'bg-dark-subtle text-dark border border-dark-subtle',
    };
  }
}
