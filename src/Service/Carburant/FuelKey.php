<?php
// src/Service/Carburant/FuelKey.php

namespace App\Service\Carburant;

final class FuelKey
{
  public static function norm(?string $s): ?string
  {
    $s = trim((string)$s);
    if ($s === '') return null;

    $s = mb_strtolower($s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $s);
    return trim((string)$s);
  }

  public static function normPlate(?string $s): ?string
  {
    $s = strtoupper(trim((string)$s));
    if ($s === '') return null;

    // retire espaces / tirets
    $s = preg_replace('/[\s\-]/', '', $s);
    // garde alphanum
    $s = preg_replace('/[^A-Z0-9]/', '', $s);
    return $s ?: null;
  }
}
