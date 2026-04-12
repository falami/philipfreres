<?php
// src/Repository/FuelCalendarRepository.php

namespace App\Repository;

use App\Entity\Entite;
use Doctrine\DBAL\Connection;

final class FuelCalendarRepository
{
  public function __construct(private readonly Connection $db) {}

  /**
   * Calendrier général : total/jour sur la période + filtres (providers, engins, employés, libellé)
   * @return array<int,array<string,mixed>> FullCalendar events
   */
  public function eventsDailyTotals(Entite $entite, array $f): array
  {
    [$sqlBase, $where, $params] = $this->buildWhere($entite, $f);

    $rows = $this->db->fetchAllAssociative("
      SELECT
        DATE(x.date_tx) AS d,
        SUM(x.amount_cents) AS amount_cents,
        COUNT(*) AS cnt
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY DATE(x.date_tx)
      ORDER BY d ASC
    ", $params);

    return $this->toFcEvents($rows, 'general');
  }

  public function eventsDailyTotalsByEngin(Entite $entite, int $enginId, array $f): array
  {
    // force engin
    $f['enginIds'] = [$enginId];

    [$sqlBase, $where, $params] = $this->buildWhere($entite, $f);

    $rows = $this->db->fetchAllAssociative("
      SELECT
        DATE(x.date_tx) AS d,
        SUM(x.amount_cents) AS amount_cents,
        COUNT(*) AS cnt
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY DATE(x.date_tx)
      ORDER BY d ASC
    ", $params);

    return $this->toFcEvents($rows, 'engin');
  }

  public function eventsDailyTotalsByEmploye(Entite $entite, int $userId, array $f): array
  {
    $f['employeIds'] = [$userId];

    [$sqlBase, $where, $params] = $this->buildWhere($entite, $f);

    $rows = $this->db->fetchAllAssociative("
      SELECT
        DATE(x.date_tx) AS d,
        SUM(x.amount_cents) AS amount_cents,
        COUNT(*) AS cnt
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY DATE(x.date_tx)
      ORDER BY d ASC
    ", $params);

    return $this->toFcEvents($rows, 'employe');
  }

  // ==========================
  // Internals
  // ==========================

  /**
   * @return array{0:string,1:string,2:array<string,mixed>}
   */
  private function buildWhere(Entite $entite, array $f): array
  {
    $params = [
      'entite' => $entite->getId(),
      'ds' => $f['dateStart'],
      'de' => $f['dateEnd'],
    ];

    $where = " x.entite_id = :entite AND x.date_tx BETWEEN :ds AND :de ";

    $providers = $this->normalizeProviders($f['providers'] ?? []);
    if ($providers !== []) {
      $in = [];
      foreach ($providers as $i => $p) {
        $k = 'p' . $i;
        $in[] = ':' . $k;
        $params[$k] = $p; // lower
      }
      $where .= " AND x.provider IN (" . implode(',', $in) . ") ";
    }

    if (!empty($f['enginIds']) && is_array($f['enginIds'])) {
      $in = [];
      foreach (array_values($f['enginIds']) as $i => $id) {
        $k = 'engin' . $i;
        $in[] = ':' . $k;
        $params[$k] = (int) $id;
      }
      $where .= " AND x.engin_id IN (" . implode(',', $in) . ") ";
    }

    if (!empty($f['employeIds']) && is_array($f['employeIds'])) {
      $in = [];
      foreach (array_values($f['employeIds']) as $i => $id) {
        $k = 'emp' . $i;
        $in[] = ':' . $k;
        $params[$k] = (int) $id;
      }
      $where .= " AND x.employe_id IN (" . implode(',', $in) . ") ";
    }

    // types de dépense (multi)
    if (!empty($f['produitIds']) && is_array($f['produitIds'])) {
      $in = [];
      foreach (array_values($f['produitIds']) as $i => $id) {
        $k = 'td' . $i;
        $in[] = ':' . $k;
        $params[$k] = (int) $id;
      }
      $where .= " AND x.produit_id IN (" . implode(',', $in) . ") ";
    }


    // ✅ Catégories produit (multi)
    if (!empty($f['categorieProduits']) && is_array($f['categorieProduits'])) {
      $in = [];
      foreach (array_values($f['categorieProduits']) as $i => $v) {
        $k = 'cat' . $i;
        $in[] = ':' . $k;
        $params[$k] = (string) $v;
      }
      $where .= " AND x.produit_cat IN (" . implode(',', $in) . ") ";
    }

    // ✅ Sous-catégories produit (multi)
    if (!empty($f['sousCategorieProduits']) && is_array($f['sousCategorieProduits'])) {
      $in = [];
      foreach (array_values($f['sousCategorieProduits']) as $i => $v) {
        $k = 'sous' . $i;
        $in[] = ':' . $k;
        $params[$k] = (string) $v;
      }
      $where .= " AND x.produit_sous IN (" . implode(',', $in) . ") ";
    }



    return [$this->unionSql(), $where, $params];
  }

  private function toFcEvents(array $rows, string $scope): array
  {
    $money = function (int $cents): string {
      $n = $cents / 100;
      return number_format($n, 2, ',', ' ') . ' €';
    };

    $events = [];
    foreach ($rows as $r) {
      $d = (string)($r['d'] ?? '');
      if ($d === '') continue;

      $amount = (int)($r['amount_cents'] ?? 0);
      $cnt    = (int)($r['cnt'] ?? 0);

      $events[] = [
        'id' => $scope . ':' . $d,
        'title' => $money($amount) . ($cnt ? "  •  {$cnt}" : ''),
        'start' => $d,
        'allDay' => true,
        'extendedProps' => [
          'amount_cents' => $amount,
          'count' => $cnt,
          'date' => $d,
          'scope' => $scope,
        ],
      ];
    }
    return $events;
  }

  private function normalizeProviders(array $providers): array
  {
    $allowed = ['alx', 'total', 'edenred'];
    $out = [];

    foreach ($providers as $p) {
      $p = strtolower(trim((string) $p));
      if ($p === '') continue;
      if (in_array($p, $allowed, true)) $out[] = $p;
    }

    return array_values(array_unique($out));
  }

  private function unionSql(): string
  {
    return "
    /* ===================== ALX ===================== */
    SELECT
      'alx' AS provider,
      t.id,
      t.entite_id,
      t.journee AS date_tx,
      NULL AS carte,
      t.vehicule AS veh_key,
      NULL AS site,
      COALESCE(CAST(t.quantite AS DECIMAL(12,3)),0) AS qty,
      CAST(ROUND(
        COALESCE(CAST(t.quantite AS DECIMAL(12,3)),0)
        * COALESCE(CAST(t.prix_unitaire AS DECIMAL(12,4)),0)
        * 100
      ) AS SIGNED) AS amount_cents,
      CONCAT(COALESCE(t.vehicule,''), ' — ', COALESCE(t.agent,'')) AS label,

      COALESCE(t.engin_id, ee.engin_id) AS engin_id,
      e.nom AS engin_label,

      COALESCE(t.utilisateur_id, ue.utilisateur_id) AS employe_id,
      CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,'')) AS employe_label,


    FROM transaction_carte_alx t

    LEFT JOIN engin_external_id ee
      ON ee.provider = 'alx'
      AND ee.active = 1
      AND ee.value = t.vehicule

    LEFT JOIN utilisateur_external_id ue
      ON ue.provider = 'alx'
      AND ue.active = 1
      AND ue.value = t.agent

    /* 🔥 Produit mapping ALX: cuve (int) */
    LEFT JOIN produit_external_id tde
      ON tde.provider = 'alx'
      AND tde.active = 1
      AND tde.value = CAST(t.cuve AS CHAR)

    LEFT JOIN produit td ON td.id = tde.produit_id

    LEFT JOIN engin e
      ON e.id = COALESCE(t.engin_id, ee.engin_id)

    LEFT JOIN utilisateur u
      ON u.id = COALESCE(t.utilisateur_id, ue.utilisateur_id)

    UNION ALL

    /* ===================== TOTAL ===================== */
    SELECT
      'total' AS provider,
      t.id,
      t.entite_id,
      t.date_transaction AS date_tx,
      t.numero_carte AS carte,
      t.nom_personnalise_carte AS veh_key,
      t.ville AS site,
      COALESCE(CAST(t.quantite AS DECIMAL(12,3)),0) AS qty,
      CAST(ROUND(COALESCE(CAST(t.montant_ttc_eur AS DECIMAL(12,2)),0) * 100) AS SIGNED) AS amount_cents,
      COALESCE(t.produit,'') AS label,

      COALESCE(t.engin_id, ee.engin_id) AS engin_id,
      e.nom AS engin_label,

      COALESCE(t.utilisateur_id, ue.utilisateur_id) AS employe_id,
      CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,'')) AS employe_label,


    FROM transaction_carte_total t

    LEFT JOIN engin_external_id ee
      ON ee.provider = 'total'
      AND ee.active = 1
      AND ee.value = t.nom_personnalise_carte

    LEFT JOIN utilisateur_external_id ue
      ON ue.provider = 'total'
      AND ue.active = 1
      AND ue.value = t.code_conducteur

    /* 🔥 Produit mapping Total: categorie_libelle_produit */
    LEFT JOIN produit_external_id tde
      ON tde.provider = 'total'
      AND tde.active = 1
      AND tde.value = t.categorie_libelle_produit

    LEFT JOIN produit td ON td.id = tde.produit_id

    LEFT JOIN engin e
      ON e.id = COALESCE(t.engin_id, ee.engin_id)

    LEFT JOIN utilisateur u
      ON u.id = COALESCE(t.utilisateur_id, ue.utilisateur_id)

    UNION ALL

    /* ===================== EDENRED ===================== */
    SELECT
      'edenred' AS provider,
      t.id,
      t.entite_id,
      t.date_transaction AS date_tx,
      t.carte_numero AS carte,
      COALESCE(t.immatriculation, t.kilometrage) AS veh_key,
      COALESCE(t.site_libelle_court, t.site_libelle) AS site,
      COALESCE(CAST(t.quantite AS DECIMAL(12,3)),0) AS qty,
      CAST(ROUND(COALESCE(CAST(t.montant_ttc AS DECIMAL(12,2)),0) * 100) AS SIGNED) AS amount_cents,
      COALESCE(t.produit,'') AS label,

      COALESCE(t.engin_id, ee.engin_id) AS engin_id,
      e.nom AS engin_label,

      COALESCE(t.utilisateur_id, ue.utilisateur_id) AS employe_id,
      CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,'')) AS employe_label,


    FROM transaction_carte_edenred t

    LEFT JOIN engin_external_id ee
      ON ee.provider = 'edenred'
      AND ee.active = 1
      AND ee.value = COALESCE(t.immatriculation, t.kilometrage)

    LEFT JOIN utilisateur_external_id ue
      ON ue.provider = 'edenred'
      AND ue.active = 1
      AND ue.value = t.code_vehicule

    /* 🔥 Produit mapping Edenred: produit */
    LEFT JOIN produit_external_id tde
      ON tde.provider = 'edenred'
      AND tde.active = 1
      AND tde.value = t.produit

    LEFT JOIN produit td ON td.id = tde.produit_id

    LEFT JOIN engin e
      ON e.id = COALESCE(t.engin_id, ee.engin_id)

    LEFT JOIN utilisateur u
      ON u.id = COALESCE(t.utilisateur_id, ue.utilisateur_id)
  ";
  }
}
