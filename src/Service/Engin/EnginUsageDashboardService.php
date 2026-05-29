<?php

namespace App\Service\Engin;

use App\Entity\Entite;
use App\Repository\EnginUsageReleveRepository;

final class EnginUsageDashboardService
{
  public function __construct(
    private readonly EnginUsageReleveRepository $repo,
  ) {}

  public function build(Entite $entite, array $filters): array
  {
    $rows = $this->repo->fetchDashboardReleves($entite, $filters);

    $byEnginRaw = [];
    foreach ($rows as $row) {
      $enginId = (int) $row['enginId'];
      $byEnginRaw[$enginId][] = $row;
    }

    $totalUsage = 0.0;
    $totalDays = 0;
    $nbIntervals = 0;

    $daily = [];
    $weekly = [];
    $monthly = [];
    $byEngin = [];

    foreach ($byEnginRaw as $enginId => $releves) {
      $previous = null;

      foreach ($releves as $current) {
        if (!$previous) {
          $previous = $current;
          continue;
        }

        $datePrev = $previous['dateReleve'];
        $dateCur = $current['dateReleve'];

        if (!$datePrev instanceof \DateTimeInterface || !$dateCur instanceof \DateTimeInterface) {
          $previous = $current;
          continue;
        }

        $days = max(1, (int) $datePrev->diff($dateCur)->days);
        $delta = round((float) $current['valeur'] - (float) $previous['valeur'], 2);

        // Ignore les compteurs remis à zéro ou erreurs de saisie
        if ($delta < 0) {
          $previous = $current;
          continue;
        }

        $enginNom = $current['enginNom'] ?? 'Engin';
        $type = $this->normalizeCompteurType($current['compteurType'] ?? null);
        $unit = $type === 'kilometre' ? 'km' : 'h';

        $dayKey = $dateCur->format('Y-m-d');
        $weekKey = $dateCur->format('o-\WW');
        $monthKey = $dateCur->format('Y-m');

        $daily[$dayKey] = ($daily[$dayKey] ?? 0) + $delta;
        $weekly[$weekKey] = ($weekly[$weekKey] ?? 0) + $delta;
        $monthly[$monthKey] = ($monthly[$monthKey] ?? 0) + $delta;

        if (!isset($byEngin[$enginId])) {
          $byEngin[$enginId] = [
            'engin' => $enginNom,
            'type' => $type,
            'unit' => $unit,
            'usage' => 0.0,
            'days' => 0,
            'nbIntervals' => 0,
            'avgDaily' => 0.0,
            'avgWeekly' => 0.0,
            'avgMonthly' => 0.0,
            'fuelLitres' => 0.0,
            'consumption' => 0.0,
          ];
        }

        $byEngin[$enginId]['usage'] += $delta;
        $litres = $this->repo->sumFuelLitresBetween(
          $entite,
          $enginId,
          $datePrev,
          $dateCur
        );

        $byEngin[$enginId]['fuelLitres'] += $litres;
        $byEngin[$enginId]['days'] += $days;
        $byEngin[$enginId]['nbIntervals']++;

        $totalUsage += $delta;
        $totalDays += $days;
        $nbIntervals++;

        $previous = $current;
      }
    }

    foreach ($byEngin as &$engin) {
      $days = max(1, $engin['days']);

      $engin['usage'] = round($engin['usage'], 2);
      $engin['avgDaily'] = round($engin['usage'] / $days, 2);
      $engin['avgWeekly'] = round(($engin['usage'] / $days) * 7, 2);
      $engin['avgMonthly'] = round(($engin['usage'] / $days) * 30.44, 2);
      $engin['fuelLitres'] = round($engin['fuelLitres'], 2);

      if ($engin['usage'] > 0 && $engin['fuelLitres'] > 0) {
        $engin['consumption'] = $engin['type'] === 'kilometre'
          ? round(($engin['fuelLitres'] / $engin['usage']) * 100, 2)
          : round($engin['fuelLitres'] / $engin['usage'], 2);
      }
    }
    unset($engin);

    ksort($daily);
    ksort($weekly);
    ksort($monthly);

    return [
      'kpis' => [
        'totalUsage' => round($totalUsage, 2),
        'avgDaily' => $totalDays > 0 ? round($totalUsage / $totalDays, 2) : 0,
        'avgWeekly' => $totalDays > 0 ? round(($totalUsage / $totalDays) * 7, 2) : 0,
        'avgMonthly' => $totalDays > 0 ? round(($totalUsage / $totalDays) * 30.44, 2) : 0,
        'nbReleves' => count($rows),
        'nbIntervals' => $nbIntervals,
      ],
      'charts' => [
        'dailyUsage' => $this->mapChart($daily, 'date', 'usage'),
        'weeklyUsage' => $this->mapChart($weekly, 'week', 'usage'),
        'monthlyUsage' => $this->mapChart($monthly, 'month', 'usage'),
        'byEngin' => array_values($byEngin),
      ],
    ];
  }

  private function mapChart(array $data, string $labelKey, string $valueKey): array
  {
    $out = [];

    foreach ($data as $key => $value) {
      $out[] = [
        $labelKey => $key,
        $valueKey => round((float) $value, 2),
      ];
    }

    return $out;
  }

  private function normalizeCompteurType(mixed $value): string
  {
    if ($value instanceof \BackedEnum) {
      return $value->value;
    }

    return (string) $value;
  }
}
