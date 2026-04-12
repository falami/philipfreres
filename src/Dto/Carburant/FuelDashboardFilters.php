<?php

namespace App\Dto\Carburant;

final class FuelDashboardFilters
{
  public ?string $dateStart = null;
  public ?string $dateEnd = null;

  /** @var string[] */
  public array $providers = [];
  public bool $providersNone = false;

  /** @var int[] */
  public array $enginIds = [];
  public bool $enginNone = false;
  public bool $enginUncategorized = false;

  /** @var int[] */
  public array $employeIds = [];
  public bool $employeNone = false;
  public bool $employeUncategorized = false;

  /** @var string[] */
  public array $categorieProduits = [];
  public bool $categorieNone = false;
  public bool $categorieUncategorized = false;

  /** @var string[] */
  public array $sousCategorieProduits = [];
  public bool $sousCategorieNone = false;
  public bool $sousUncategorized = false;

  public string $calendarGranularity = 'month'; // day|month|year

  public static function fromArray(array $q): self
  {
    $f = new self();

    $f->dateStart = self::cleanString($q['dateStart'] ?? null);
    $f->dateEnd   = self::cleanString($q['dateEnd'] ?? null);

    $f->providers = self::cleanStringArray($q['providers'] ?? $q['providers[]'] ?? []);
    $f->providersNone = self::bool($q['providersNone'] ?? false);

    $f->enginIds = self::cleanIntArray($q['enginIds'] ?? $q['enginIds[]'] ?? []);
    $f->enginNone = self::bool($q['enginNone'] ?? false);
    $f->enginUncategorized = self::bool($q['enginUncategorized'] ?? false);

    $f->employeIds = self::cleanIntArray($q['employeIds'] ?? $q['employeIds[]'] ?? []);
    $f->employeNone = self::bool($q['employeNone'] ?? false);
    $f->employeUncategorized = self::bool($q['employeUncategorized'] ?? false);

    $f->categorieProduits = self::cleanStringArray($q['categorieProduits'] ?? $q['categorieProduits[]'] ?? []);
    $f->categorieNone = self::bool($q['categorieNone'] ?? false);
    $f->categorieUncategorized = self::bool($q['categorieUncategorized'] ?? false);

    $f->sousCategorieProduits = self::cleanStringArray($q['sousCategorieProduits'] ?? $q['sousCategorieProduits[]'] ?? []);
    $f->sousCategorieNone = self::bool($q['sousCategorieNone'] ?? false);
    $f->sousUncategorized = self::bool($q['sousUncategorized'] ?? false);

    $granularity = self::cleanString($q['calendarGranularity'] ?? 'month') ?? 'month';
    $f->calendarGranularity = \in_array($granularity, ['day', 'month', 'year'], true) ? $granularity : 'month';

    return $f;
  }

  private static function cleanString(mixed $v): ?string
  {
    if (!\is_string($v)) {
      return null;
    }

    $v = trim($v);
    return $v === '' ? null : $v;
  }

  /**
   * @return string[]
   */
  private static function cleanStringArray(mixed $values): array
  {
    if (!\is_array($values)) {
      $values = [$values];
    }

    $out = [];
    foreach ($values as $v) {
      if (!\is_string($v)) {
        continue;
      }
      $v = trim($v);
      if ($v !== '') {
        $out[] = $v;
      }
    }

    return array_values(array_unique($out));
  }

  /**
   * @return int[]
   */
  private static function cleanIntArray(mixed $values): array
  {
    if (!\is_array($values)) {
      $values = [$values];
    }

    $out = [];
    foreach ($values as $v) {
      if ($v === null || $v === '') {
        continue;
      }
      $i = (int) $v;
      if ($i > 0) {
        $out[] = $i;
      }
    }

    return array_values(array_unique($out));
  }

  private static function bool(mixed $v): bool
  {
    return \in_array($v, [true, 1, '1', 'true', 'on'], true);
  }
}
