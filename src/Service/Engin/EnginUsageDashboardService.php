<?php

namespace App\Service\Engin;

use App\Entity\Entite;
use App\Entity\EnginUsageReleve;
use App\Entity\TransactionCarteAlx;
use App\Entity\TransactionCarteTotal;
use App\Entity\TransactionCarteEdenred;
use Doctrine\ORM\EntityManagerInterface;

final class EnginUsageDashboardService
{
  public function __construct(
    private readonly EntityManagerInterface $em,
  ) {}

  public function build(Entite $entite, array $filters): array
  {
    $usage = $this->getUsageData($entite, $filters);
    $expenses = $this->getExpenseData($entite, $filters);

    $byEngin = [];

    foreach ($usage['byEngin'] as $row) {
      $enginId = (int) $row['enginId'];

      $byEngin[$enginId] = [
        'enginId' => $enginId,
        'engin' => $row['engin'],
        'type' => $this->enumValue($row['type']),
        'usage' => (float) $row['usage'],
        'avgUsage' => (float) $row['avgUsage'],
        'depense' => 0.0,
        'coutUnite' => 0.0,
        'nb' => (int) $row['nb'],
      ];
    }

    foreach ($expenses['byEngin'] as $row) {
      $enginId = (int) $row['enginId'];

      if (!isset($byEngin[$enginId])) {
        $byEngin[$enginId] = [
          'enginId' => $enginId,
          'engin' => $row['engin'],
          'type' => null,
          'usage' => 0.0,
          'avgUsage' => 0.0,
          'depense' => 0.0,
          'coutUnite' => 0.0,
          'nb' => 0,
        ];
      }

      $byEngin[$enginId]['depense'] += (float) $row['depense'];
    }

    foreach ($byEngin as &$row) {
      $row['coutUnite'] = $row['usage'] > 0
        ? round($row['depense'] / $row['usage'], 2)
        : 0.0;
    }

    $totalUsage = array_sum(array_column($byEngin, 'usage'));
    $totalDepense = array_sum(array_column($byEngin, 'depense'));
    $nbReleves = $usage['nbReleves'];

    return [
      'kpis' => [
        'totalUsage' => round($totalUsage, 2),
        'avgUsage' => $nbReleves > 0 ? round($totalUsage / $nbReleves, 2) : 0,
        'totalDepense' => round($totalDepense, 2),
        'coutMoyenUnite' => $totalUsage > 0 ? round($totalDepense / $totalUsage, 2) : 0,
        'nbReleves' => $nbReleves,
      ],
      'charts' => [
        'monthlyUsage' => $usage['monthly'],
        'monthlyExpense' => $expenses['monthly'],
        'byEngin' => array_values($byEngin),
      ],
    ];
  }

  private function getUsageData(Entite $entite, array $filters): array
  {
    $qb = $this->em->createQueryBuilder()
      ->from(EnginUsageReleve::class, 'r')
      ->join('r.engin', 'e')
      ->where('r.entite = :entite')
      ->andWhere('r.dateReleve BETWEEN :start AND :end')
      ->setParameter('entite', $entite)
      ->setParameter('start', new \DateTimeImmutable($filters['dateStart']))
      ->setParameter('end', new \DateTimeImmutable($filters['dateEnd'] . ' 23:59:59'));

    if (!empty($filters['enginId'])) {
      $qb->andWhere('e.id = :enginId')
        ->setParameter('enginId', (int) $filters['enginId']);
    }

    $monthly = (clone $qb)
      ->select("
                SUBSTRING(r.dateReleve, 1, 7) AS month,
                e.compteurType AS type,
                SUM(r.valeur) AS usage,
                AVG(r.valeur) AS avgUsage,
                COUNT(r.id) AS nb
            ")
      ->groupBy('month', 'e.compteurType')
      ->orderBy('month', 'ASC')
      ->getQuery()
      ->getArrayResult();

    $byEngin = (clone $qb)
      ->select("
                e.id AS enginId,
                e.nom AS engin,
                e.compteurType AS type,
                SUM(r.valeur) AS usage,
                AVG(r.valeur) AS avgUsage,
                COUNT(r.id) AS nb
            ")
      ->groupBy('e.id')
      ->orderBy('usage', 'DESC')
      ->getQuery()
      ->getArrayResult();

    return [
      'monthly' => array_map(fn(array $r) => [
        'month' => $r['month'],
        'type' => $this->enumValue($r['type']),
        'usage' => (float) $r['usage'],
        'avgUsage' => (float) $r['avgUsage'],
        'nb' => (int) $r['nb'],
      ], $monthly),
      'byEngin' => $byEngin,
      'nbReleves' => array_sum(array_map(fn($r) => (int) $r['nb'], $monthly)),
    ];
  }

  private function getExpenseData(Entite $entite, array $filters): array
  {
    $rows = [];

    $rows = array_merge($rows, $this->expenseQuery(
      TransactionCarteAlx::class,
      't.journee',
      '(COALESCE(t.quantite, 0) * COALESCE(t.prixUnitaire, 0))',
      $entite,
      $filters
    ));

    $rows = array_merge($rows, $this->expenseQuery(
      TransactionCarteTotal::class,
      't.dateTransaction',
      'COALESCE(t.montantTtcEur, 0)',
      $entite,
      $filters
    ));

    $rows = array_merge($rows, $this->expenseQuery(
      TransactionCarteEdenred::class,
      't.dateTransaction',
      'COALESCE(t.montantTtc, 0)',
      $entite,
      $filters
    ));

    $monthly = [];
    $byEngin = [];

    foreach ($rows as $row) {
      $month = $row['month'];
      $enginId = (int) $row['enginId'];
      $depense = (float) $row['depense'];

      $monthly[$month] ??= [
        'month' => $month,
        'depense' => 0.0,
      ];

      $monthly[$month]['depense'] += $depense;

      $byEngin[$enginId] ??= [
        'enginId' => $enginId,
        'engin' => $row['engin'],
        'depense' => 0.0,
      ];

      $byEngin[$enginId]['depense'] += $depense;
    }

    ksort($monthly);

    return [
      'monthly' => array_values($monthly),
      'byEngin' => array_values($byEngin),
    ];
  }

  private function expenseQuery(
    string $entityClass,
    string $dateField,
    string $amountExpression,
    Entite $entite,
    array $filters
  ): array {
    $qb = $this->em->createQueryBuilder()
      ->from($entityClass, 't')
      ->join('t.engin', 'e')
      ->select("
            e.id AS enginId,
            e.nom AS engin,
            SUBSTRING($dateField, 1, 7) AS month,
            SUM($amountExpression) AS depense
        ")
      ->where('t.entite = :entite')
      ->andWhere("$dateField BETWEEN :start AND :end")
      ->andWhere('t.engin IS NOT NULL')
      ->setParameter('entite', $entite)
      ->setParameter('start', new \DateTimeImmutable($filters['dateStart']))
      ->setParameter('end', new \DateTimeImmutable($filters['dateEnd'] . ' 23:59:59'))
      ->groupBy('e.id', 'month');

    if (!empty($filters['enginId'])) {
      $qb->andWhere('e.id = :enginId')
        ->setParameter('enginId', (int) $filters['enginId']);
    }

    return $qb->getQuery()->getArrayResult();
  }


  private function enumValue(mixed $value): ?string
  {
    return $value instanceof \BackedEnum ? $value->value : ($value ? (string) $value : null);
  }
}
