<?php

namespace App\Enum;

enum SousCategorieProduit: string
{
  // --- ENERGIE ---
  case GASOIL = 'gasoil';
  case ESSENCE = 'essence';
  case ETHANOL = 'ethanol';
  case ADBLUE = 'adblue';
  case GNR = 'gnr'; // Ce que tu as cité
  case HVO100 = 'HVO100'; // Ce que tu as cité

    // --- ROUTE ---
  case PEAGE = 'peage';
  case PARKING = 'parking';

    // --- ENTRETIEN ---
  case LUBRIFIANT = 'lubrifiant'; // Huiles, etc.
  case LAVAGE = 'lavage';

    // --- EQUIPEMENT ---
  case ACCESSOIRE = 'accessoire'; // Ce que tu as cité
  case BOUTIQUE = 'boutique'; // Ce que tu as cité

  public function label(): string
  {
    return match ($this) {
      self::GASOIL => 'Gasoil',
      self::ESSENCE => 'Essence',
      self::ETHANOL => 'Éthanol (E85)',
      self::ADBLUE => 'AdBlue',
      self::PEAGE => 'Péage',
      self::PARKING => 'Parking',
      self::LUBRIFIANT => 'Lubrifiants / Huiles',
      self::LAVAGE => 'Lavage / Entretien',
      self::ACCESSOIRE => 'Accessoires Voiture',
      self::BOUTIQUE => 'Boutique',
      self::GNR => 'GNR',
      self::HVO100 => 'HVO 100',
    };
  }

  public function getParentCategory(): CategorieProduit
  {
    return match ($this) {
      self::GASOIL, self::ESSENCE, self::GNR, self::ETHANOL, self::ADBLUE, self::HVO100 => CategorieProduit::CARBURANT,
      self::PEAGE, self::PARKING => CategorieProduit::ROUTE,
      self::LUBRIFIANT, self::LAVAGE => CategorieProduit::ENTRETIEN,
      self::ACCESSOIRE => CategorieProduit::ACCESSOIRE,
    };
  }
}
