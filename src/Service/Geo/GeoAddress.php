<?php

declare(strict_types=1);

namespace App\Service\Geo;

final class GeoAddress
{
  public static function normalize(?string $address): string
  {
    $a = trim((string)$address);
    $a = preg_replace('/\s+/', ' ', $a) ?? $a;
    $a = mb_strtolower($a, 'UTF-8');
    // supprime ponctuation "soft"
    $a = preg_replace('/[;|]+/', ' ', $a) ?? $a;
    $a = trim($a);
    return $a;
  }

  public static function hash(string $normalized): string
  {
    return hash('sha256', $normalized);
  }
}
