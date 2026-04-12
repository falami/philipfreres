<?php

namespace App\Enum;

enum CategorieProduit: string
{
  case CARBURANT = 'carburant';
  case ROUTE = 'route';
  case ENTRETIEN = 'entretien';
  case DIVERS = 'divers';
  case ACCESSOIRE = 'accessoire';

  public function label(): string
  {
    return match ($this) {
      self::CARBURANT => 'Carburants & Énergie',
      self::ROUTE => 'Frais de Route',
      self::ENTRETIEN => 'Entretien & Fluides',
      self::DIVERS => 'Divers',
      self::ACCESSOIRE => 'Accessoires',
    };
  }

  public function badgeColor(): string
  {
    return match ($this) {
      self::CARBURANT => '#dc3545',   // Rouge (Vital/Consommable)
      self::ROUTE => '#ffc107',     // Jaune (Usage)
      self::ENTRETIEN => '#0d6efd',  // Bleu (Technique)
      self::DIVERS => '#6c757d', // Gris (Matériel)
      self::ACCESSOIRE => '#6c757d', // Gris (Matériel)
    };
  }
}
