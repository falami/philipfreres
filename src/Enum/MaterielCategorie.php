<?php

namespace App\Enum;

enum MaterielCategorie: string
{
  case TRONCONNEUSE = 'tronconneuse';
  case DEBROUSSAILLEUSE = 'debroussailleuse';
  case EPAREUSE = 'epareuse';
  case OUTILLAGE = 'outillage';
  case EPI = 'epi';
  case CORDE = 'corde';
  case CONSOMMABLE = 'consommable';
  case BALISAGE = 'balisage';
  case AUTRE = 'autre';

  public function label(): string
  {
    return match ($this) {
      self::TRONCONNEUSE => 'Tronçonneuse',
      self::DEBROUSSAILLEUSE => 'Débroussailleuse',
      self::EPAREUSE => 'Epareuse',
      self::OUTILLAGE => 'Outillage',
      self::EPI => 'EPI',
      self::CORDE => 'Cordage / Grimpe',
      self::CONSOMMABLE => 'Consommable',
      self::BALISAGE => 'Balisage',
      self::AUTRE => 'Autre',
    };
  }
}
