<?php

namespace App\Enum;

enum EnginType: string
{
    case ABATTEUSE = 'Abatteuse';
    case PELLE = 'pelle';
    case PORTEUR = 'porteur';
    case CHARGEUSE = 'chargeuse';
    case TRACTEUR = 'tracteur';
    case NACELLE = 'nacelle';
    case PORTE_OUTIL = 'porte-outil';
    case MATERIEL_FLUVIAL = 'materiel_fluvial';
    case BROYEUR = 'broyeur';
    case PETIT_ENGIN = 'petit_engin';
    case CAMION = 'camion';
    case ACCESSOIRE = 'accessoire';

    public function label(): string
    {
        return match ($this) {
            self::ABATTEUSE         => 'Abatteuse',
            self::PELLE             => 'Pelle',
            self::PORTEUR           => 'Porteur',
            self::CHARGEUSE         => 'Chargeuse',
            self::TRACTEUR          => 'Tracteur',
            self::NACELLE           => 'Nacelle',
            self::PORTE_OUTIL       => 'Porte-outil',
            self::MATERIEL_FLUVIAL  => 'Matériel fluvial',
            self::BROYEUR           => 'Broyeur',
            self::PETIT_ENGIN       => 'Petit engin',
            self::CAMION            => 'Camion',
            self::ACCESSOIRE        => 'Accessoire',
        };
    }
}
