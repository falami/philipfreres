<?php

namespace App\Repository\Carburant;

use App\Dto\Carburant\FuelDashboardFilters;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;

final class FuelAnalyticsRepository
{
  public function __construct(
    private readonly EntityManagerInterface $em,
  ) {}

  private function conn(): Connection
  {
    return $this->em->getConnection();
  }

  private function unifiedBaseSql(): string
  {
    return <<<SQL
(
    SELECT
        CONCAT('NOTE-', n.id) AS tx_id,
        'NOTE' AS provider,
        n.entite_id AS entite_id,
        n.utilisateur_id AS utilisateur_id,
        n.engin_id AS engin_id,
        n.produit_id AS produit_id,
        COALESCE(n.libelle, 'Note') AS label,
        n.commentaire AS commentaire,
        COALESCE(n.quantite, 0) AS qty,
        COALESCE(ROUND(n.montant_ttc_eur * 100), 0) AS amount_cents,
        n.date_transaction AS start_at,
        DATE_ADD(n.date_transaction, INTERVAL 45 MINUTE) AS end_at,
        'note' AS source_type
    FROM note n

    UNION ALL

    SELECT
        CONCAT('ALX-', a.id) AS tx_id,
        'ALX' AS provider,
        a.entite_id AS entite_id,
        a.utilisateur_id AS utilisateur_id,
        a.engin_id AS engin_id,
        NULL AS produit_id,
        COALESCE(a.vehicule, 'ALX') AS label,
        NULL AS commentaire,
        COALESCE(a.quantite, 0) AS qty,
        COALESCE(ROUND((COALESCE(a.quantite, 0) * COALESCE(a.prix_unitaire, 0)) * 100), 0) AS amount_cents,
        CASE
            WHEN a.journee IS NOT NULL AND a.horaire IS NOT NULL THEN TIMESTAMP(a.journee, a.horaire)
            WHEN a.journee IS NOT NULL THEN TIMESTAMP(a.journee, '08:00:00')
            ELSE NULL
        END AS start_at,
        CASE
            WHEN a.journee IS NOT NULL AND a.horaire IS NOT NULL THEN DATE_ADD(TIMESTAMP(a.journee, a.horaire), INTERVAL 30 MINUTE)
            WHEN a.journee IS NOT NULL THEN DATE_ADD(TIMESTAMP(a.journee, '08:00:00'), INTERVAL 30 MINUTE)
            ELSE NULL
        END AS end_at,
        'alx' AS source_type
    FROM transaction_carte_alx a

    UNION ALL

    SELECT
        CONCAT('EDENRED-', e.id) AS tx_id,
        'EDENRED' AS provider,
        e.entite_id AS entite_id,
        e.utilisateur_id AS utilisateur_id,
        e.engin_id AS engin_id,
        NULL AS produit_id,
        COALESCE(e.produit, e.site_libelle, 'EDENRED') AS label,
        NULL AS commentaire,
        COALESCE(e.quantite, 0) AS qty,
        COALESCE(ROUND(e.montant_ttc * 100), 0) AS amount_cents,
        e.date_transaction AS start_at,
        DATE_ADD(e.date_transaction, INTERVAL 30 MINUTE) AS end_at,
        'edenred' AS source_type
    FROM transaction_carte_edenred e

    UNION ALL

    SELECT
        CONCAT('TOTAL-', t.id) AS tx_id,
        'TOTAL' AS provider,
        t.entite_id AS entite_id,
        t.utilisateur_id AS utilisateur_id,
        t.engin_id AS engin_id,
        NULL AS produit_id,
        COALESCE(t.produit, t.categorie_libelle_produit, 'TOTAL') AS label,
        NULL AS commentaire,
        COALESCE(t.quantite, 0) AS qty,
        COALESCE(ROUND(t.montant_ttc_eur * 100), 0) AS amount_cents,
        CASE
            WHEN t.date_transaction IS NOT NULL AND t.heure_transaction IS NOT NULL THEN TIMESTAMP(t.date_transaction, t.heure_transaction)
            WHEN t.date_transaction IS NOT NULL THEN TIMESTAMP(t.date_transaction, '08:00:00')
            ELSE NULL
        END AS start_at,
        CASE
            WHEN t.date_transaction IS NOT NULL AND t.heure_transaction IS NOT NULL THEN DATE_ADD(TIMESTAMP(t.date_transaction, t.heure_transaction), INTERVAL 30 MINUTE)
            WHEN t.date_transaction IS NOT NULL THEN DATE_ADD(TIMESTAMP(t.date_transaction, '08:00:00'), INTERVAL 30 MINUTE)
            ELSE NULL
        END AS end_at,
        'total' AS source_type
    FROM transaction_carte_total t
)
SQL;
  }

  private function baseQb(int $entiteId, FuelDashboardFilters $f): QueryBuilder
  {
    $conn = $this->conn();

    $qb = $conn->createQueryBuilder()
      ->from('(' . $this->unifiedBaseSql() . ')', 'u')
      ->leftJoin('u', 'utilisateur', 'usr', 'usr.id = u.utilisateur_id')
      ->leftJoin('u', 'engin', 'eng', 'eng.id = u.engin_id')
      ->where('u.entite_id = :entiteId')
      ->andWhere('u.start_at IS NOT NULL')
      ->setParameter('entiteId', $entiteId);

    if ($f->dateStart) {
      $qb->andWhere('DATE(u.start_at) >= :dateStart')
        ->setParameter('dateStart', $f->dateStart);
    }

    if ($f->dateEnd) {
      $qb->andWhere('DATE(u.start_at) <= :dateEnd')
        ->setParameter('dateEnd', $f->dateEnd);
    }

    if ($f->providersNone) {
      $qb->andWhere('1 = 0');
    } elseif ($f->providers !== []) {
      $qb->andWhere('u.provider IN (:providers)')
        ->setParameter('providers', $f->providers, ArrayParameterType::STRING);
    }

    if ($f->enginNone) {
      $qb->andWhere('1 = 0');
    } elseif ($f->enginIds !== []) {
      $qb->andWhere('u.engin_id IN (:enginIds)')
        ->setParameter('enginIds', $f->enginIds, ArrayParameterType::INTEGER);
    }

    if ($f->employeNone) {
      $qb->andWhere('1 = 0');
    } elseif ($f->employeIds !== []) {
      $qb->andWhere('u.utilisateur_id IN (:employeIds)')
        ->setParameter('employeIds', $f->employeIds, ArrayParameterType::INTEGER);
    }

    return $qb;
  }

  public function fetchPlanningMatrix(int $entiteId, FuelDashboardFilters $f): array
  {
    $rows = $this->baseQb($entiteId, $f)
      ->select("
                u.tx_id,
                u.provider,
                u.label,
                u.commentaire,
                u.qty,
                u.amount_cents,
                u.start_at,
                u.end_at,
                u.source_type,
                DATE(u.start_at) AS day_key,
                COALESCE(u.utilisateur_id, 0) AS user_id,
                COALESCE(CONCAT(usr.prenom, ' ', usr.nom), 'Non catégorisé') AS user_label,
                CASE WHEN u.engin_id IS NULL THEN 0 ELSE u.engin_id END AS engin_id,
                COALESCE(eng.nom, 'Non catégorisé') AS engin_label
            ")
      ->orderBy('user_label', 'ASC')
      ->addOrderBy('engin_label', 'ASC')
      ->addOrderBy('u.start_at', 'ASC')
      ->executeQuery()
      ->fetchAllAssociative();

    $start = $f->dateStart ? new \DateTimeImmutable($f->dateStart) : null;
    $end = $f->dateEnd ? new \DateTimeImmutable($f->dateEnd) : null;

    if (!$start || !$end) {
      if ($rows === []) {
        $start = new \DateTimeImmutable('first day of this month');
        $end = new \DateTimeImmutable('last day of this month');
      } else {
        $dates = array_map(static fn(array $r) => new \DateTimeImmutable(substr((string) $r['day_key'], 0, 10)), $rows);
        usort($dates, static fn(\DateTimeImmutable $a, \DateTimeImmutable $b) => $a <=> $b);
        $start = $dates[0];
        $end = $dates[count($dates) - 1];
      }
    }

    $days = [];
    $cursor = $start;
    while ($cursor <= $end) {
      $days[] = [
        'date' => $cursor->format('Y-m-d'),
        'day' => $cursor->format('j'),
        'monthLabel' => $this->monthLabelFr($cursor),
      ];
      $cursor = $cursor->modify('+1 day');
    }

    $resources = [];
    $cells = [];
    $totalAmountCents = 0;
    $totalQty = 0.0;

    foreach ($rows as $row) {
      $userId = (int) $row['user_id'];
      $userLabel = (string) $row['user_label'];
      $enginId = (int) $row['engin_id'];
      $enginLabel = (string) $row['engin_label'];
      $dayKey = substr((string) $row['day_key'], 0, 10);

      $parentId = 'u_' . $userId;
      $childId = 'u_' . $userId . '_e_' . $enginId;

      if (!isset($resources[$parentId])) {
        $resources[$parentId] = [
          'id' => $parentId,
          'type' => 'user',
          'name' => $userLabel,
          'meta' => [
            'amount_cents' => 0,
            'qty' => 0.0,
            'count' => 0,
          ],
          'children' => [],
        ];
      }

      if (!isset($resources[$parentId]['children'][$childId])) {
        $resources[$parentId]['children'][$childId] = [
          'id' => $childId,
          'type' => 'engin',
          'name' => $enginId > 0 ? $enginLabel : 'Non catégorisé',
          'meta' => [
            'amount_cents' => 0,
            'qty' => 0.0,
            'count' => 0,
          ],
        ];
      }

      $amountCents = (int) $row['amount_cents'];
      $qty = (float) $row['qty'];

      $resources[$parentId]['meta']['amount_cents'] += $amountCents;
      $resources[$parentId]['meta']['qty'] += $qty;
      $resources[$parentId]['meta']['count']++;

      $resources[$parentId]['children'][$childId]['meta']['amount_cents'] += $amountCents;
      $resources[$parentId]['children'][$childId]['meta']['qty'] += $qty;
      $resources[$parentId]['children'][$childId]['meta']['count']++;

      $totalAmountCents += $amountCents;
      $totalQty += $qty;

      if (!isset($cells[$childId][$dayKey])) {
        $cells[$childId][$dayKey] = [
          'amount_cents' => 0,
          'qty' => 0.0,
          'count' => 0,
          'items' => [],
          'providers' => [],
        ];
      }

      $cells[$childId][$dayKey]['amount_cents'] += $amountCents;
      $cells[$childId][$dayKey]['qty'] += $qty;
      $cells[$childId][$dayKey]['count']++;

      $provider = strtoupper((string) $row['provider']);
      $cells[$childId][$dayKey]['providers'][$provider] = ($cells[$childId][$dayKey]['providers'][$provider] ?? 0) + 1;

      $cells[$childId][$dayKey]['items'][] = [
        'tx_id' => $row['tx_id'],
        'provider' => $provider,
        'label' => (string) $row['label'],
        'commentaire' => $row['commentaire'],
        'qty' => $qty,
        'amount_cents' => $amountCents,
        'start_at' => (string) $row['start_at'],
        'source_type' => (string) $row['source_type'],
        'user_label' => $userLabel,
        'engin_label' => $enginId > 0 ? $enginLabel : 'Non catégorisé',
      ];
    }

    $resourceList = [];
    foreach ($resources as $parent) {
      $resourceList[] = [
        'id' => $parent['id'],
        'type' => 'user',
        'name' => $parent['name'],
        'meta' => $this->formatMeta($parent['meta']),
        'children' => array_values(array_map(function (array $child) {
          return [
            'id' => $child['id'],
            'type' => 'engin',
            'name' => $child['name'],
            'meta' => $this->formatMeta($child['meta']),
          ];
        }, $parent['children'])),
      ];
    }

    $formattedCells = [];
    foreach ($cells as $resourceId => $daysMap) {
      foreach ($daysMap as $dayKey => $cell) {
        arsort($cell['providers']);
        $dominantProvider = array_key_first($cell['providers']) ?: 'NOTE';

        $formattedCells[$resourceId][$dayKey] = [
          'amount_cents' => $cell['amount_cents'],
          'amount_label' => $this->formatMoney($cell['amount_cents']),
          'qty' => $cell['qty'],
          'qty_label' => $this->formatQty($cell['qty']),
          'count' => $cell['count'],
          'provider' => $dominantProvider,
          'items' => $cell['items'],
        ];
      }
    }

    return [
      'days' => $days,
      'resources' => $resourceList,
      'cells' => $formattedCells,
      'summary' => [
        'amount_cents' => $totalAmountCents,
        'amount_label' => $this->formatMoney($totalAmountCents),
        'qty' => $totalQty,
        'qty_label' => $this->formatQty($totalQty),
        'event_count' => count($rows),
        'resource_count' => array_sum(array_map(
          static fn(array $r) => 1 + count($r['children']),
          $resourceList
        )),
      ],
    ];
  }

  private function formatMeta(array $meta): string
  {
    return sprintf(
      '%s • %s • %d tx',
      $this->formatMoney((int) $meta['amount_cents']),
      $this->formatQty((float) $meta['qty']),
      (int) $meta['count']
    );
  }

  private function formatMoney(int $amountCents): string
  {
    return number_format($amountCents / 100, 2, ',', ' ') . ' €';
  }

  private function formatQty(float $qty): string
  {
    return number_format($qty, 3, ',', ' ') . ' L';
  }

  private function monthLabelFr(\DateTimeImmutable $date): string
  {
    $months = [
      1 => 'janvier',
      2 => 'février',
      3 => 'mars',
      4 => 'avril',
      5 => 'mai',
      6 => 'juin',
      7 => 'juillet',
      8 => 'août',
      9 => 'septembre',
      10 => 'octobre',
      11 => 'novembre',
      12 => 'décembre',
    ];

    return $months[(int) $date->format('n')] . ' ' . $date->format('Y');
  }
}
