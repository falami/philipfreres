<?php
// src/Repository/FuelDashboardRepository.php

namespace App\Repository;

use App\Entity\Entite;
use Doctrine\DBAL\Connection;

final class FuelDashboardRepository
{
  public function __construct(private readonly Connection $db) {}
  private const COLL = 'utf8mb4_unicode_ci';

  /**
   * @return array{0:array<int,array<string,mixed>>,1:int,2:int}
   */
  public function fetchRows(
    Entite $entite,
    array $f,
    int $start,
    int $length,
    string $orderBy,
    string $orderDir,
    string $search
  ): array {
    $params = [
      'entite' => $entite->getId(),
      'ds' => $f['dateStart'],
      'de' => $f['dateEnd'],
    ];

    $where = " x.entite_id = :entite AND x.date_tx BETWEEN :ds AND :de ";

    // ✅ "Aucun" => aucun résultat
    if (
      !empty($f['providersNone']) || !empty($f['enginNone']) || !empty($f['employeNone'])
      || !empty($f['categorieNone']) || !empty($f['sousCategorieNone'])
    ) {
      $where .= " AND 1=0 ";
    }

    // ✅ providers: on accepte ['ALX','TOTAL','EDENRED','NOTE'] ou ['alx','total','edenred','note']
    $providers = $this->normalizeProviders($f['providers'] ?? []);
    if ($providers !== []) {
      $in = [];
      foreach ($providers as $i => $p) {
        $k = 'p' . $i;
        $in[] = ':' . $k;
        $params[$k] = $p; // lower: alx/total/edenred
      }
      $where .= " AND x.provider IN (" . implode(',', $in) . ") ";
    }



    // ✅ Engins (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.engin_id',
      'engin',
      $f['enginIds'] ?? [],
      !empty($f['enginUncategorized']),
      $params,
      $where
    );

    // ✅ Employés (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.employe_id',
      'emp',
      $f['employeIds'] ?? [],
      !empty($f['employeUncategorized']),
      $params,
      $where
    );

    // libellé
    // ✅ Catégories produit (multi)
    $this->addEnumFilterWithNullFlag(
      'x.produit_cat',
      'cat',
      $f['categorieProduits'] ?? [],
      !empty($f['categorieUncategorized']),
      $params,
      $where
    );

    $this->addEnumFilterWithNullFlag(
      'x.produit_sous',
      'sous',
      $f['sousCategorieProduits'] ?? [],
      !empty($f['sousUncategorized']),
      $params,
      $where
    );

    if ($search !== '') {
      $where .= " AND (
        x.label LIKE :q
        OR x.veh_key LIKE :q
        OR x.site LIKE :q
        OR x.carte LIKE :q
        OR x.engin_label LIKE :q
        OR x.employe_label LIKE :q
        OR x.produit_label LIKE :q
      ) ";
      $params['q'] = '%' . $search . '%';
    }

    $allowedOrder = [
      'date_tx',
      'provider',
      'qty',
      'amount_cents',
      'label',
      'engin_label',
      'employe_label',
      'produit_label',
      'produit_cat',    // ✅
      'produit_sous',   // ✅
    ];
    if (!\in_array($orderBy, $allowedOrder, true)) $orderBy = 'date_tx';
    $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

    // sécurise hard les bornes (même si tu le fais déjà côté controller)
    $length = max(1, min(500, $length));
    $start  = max(0, $start);

    $sqlBase = $this->unionSql();

    $sql = "
      SELECT *
      FROM ($sqlBase) x
      WHERE $where
      ORDER BY x.$orderBy $orderDir, x.provider ASC, x.id DESC
      LIMIT $length OFFSET $start
    ";

    $rows = $this->db->fetchAllAssociative($sql, $params);

    $sqlCount = "
      SELECT COUNT(*) c
      FROM ($sqlBase) x
      WHERE $where
    ";
    $filtered = (int) $this->db->fetchOne($sqlCount, $params);

    // total = sans filtres, mais limité à l'entité
    $sqlTotal = "
      SELECT COUNT(*) c
      FROM ($sqlBase) x
      WHERE x.entite_id = :entite
    ";
    $total = (int) $this->db->fetchOne($sqlTotal, ['entite' => $entite->getId()]);

    return [$rows, $total, $filtered];
  }

  public function summary(Entite $entite, array $f): array
  {
    $params = [
      'entite' => $entite->getId(),
      'ds' => $f['dateStart'],
      'de' => $f['dateEnd'],
    ];

    $where = " x.entite_id = :entite AND x.date_tx BETWEEN :ds AND :de ";

    // ✅ "Aucun" => aucun résultat
    if (
      !empty($f['providersNone']) || !empty($f['enginNone']) || !empty($f['employeNone'])
      || !empty($f['categorieNone']) || !empty($f['sousCategorieNone'])
    ) {
      $where .= " AND 1=0 ";
    }

    // ✅ providers normalisés
    $providers = $this->normalizeProviders($f['providers'] ?? []);
    if ($providers !== []) {
      $in = [];
      foreach ($providers as $i => $p) {
        $k = 'p' . $i;
        $in[] = ':' . $k;
        $params[$k] = $p;
      }
      $where .= " AND x.provider IN (" . implode(',', $in) . ") ";
    }

    // ✅ Engins (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.engin_id',
      'engin',
      $f['enginIds'] ?? [],
      !empty($f['enginUncategorized']),
      $params,
      $where
    );

    // ✅ Employés (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.employe_id',
      'emp',
      $f['employeIds'] ?? [],
      !empty($f['employeUncategorized']),
      $params,
      $where
    );

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

    // libellé
    // ✅ Catégories produit (multi)
    $this->addEnumFilterWithNullFlag(
      'x.produit_cat',
      'cat',
      $f['categorieProduits'] ?? [],
      !empty($f['categorieUncategorized']),
      $params,
      $where
    );

    $this->addEnumFilterWithNullFlag(
      'x.produit_sous',
      'sous',
      $f['sousCategorieProduits'] ?? [],
      !empty($f['sousUncategorized']),
      $params,
      $where
    );

    $sqlBase = $this->unionSql();

    // ===== KPI
    $kpiSql = "
      SELECT
        COALESCE(SUM(x.qty),0) qty,
        COALESCE(SUM(x.amount_cents),0) amount_cents,
        COUNT(*) cnt,
        COALESCE(SUM(CASE WHEN x.engin_id IS NULL THEN 1 ELSE 0 END),0) cnt_unmatched_engin,
        COALESCE(SUM(CASE WHEN x.employe_id IS NULL THEN 1 ELSE 0 END),0) cnt_unmatched_employe
      FROM ($sqlBase) x
      WHERE $where
    ";
    $k = $this->db->fetchAssociative($kpiSql, $params) ?: [];

    // ===== Par fournisseur
    // provider = alx/total/edenred → label en UPPER pour l'affichage
    $byProv = $this->db->fetchAllAssociative("
      SELECT UPPER(x.provider) AS label, SUM(x.amount_cents) AS v
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY x.provider
      ORDER BY v DESC
    ", $params);

    // ===== Trend mensuelle
    $trend = $this->db->fetchAllAssociative("
      SELECT DATE_FORMAT(x.date_tx, '%Y-%m') AS ym, SUM(x.amount_cents) AS amount_cents
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY DATE_FORMAT(x.date_tx, '%Y-%m')
      ORDER BY ym ASC
    ", $params);

    // ===== Top engins
    $topEng = $this->db->fetchAllAssociative("
      SELECT COALESCE(x.engin_label,'(Non rattaché)') AS label, SUM(x.amount_cents) AS v
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY COALESCE(x.engin_label,'(Non rattaché)')
      ORDER BY v DESC
      LIMIT 10
    ", $params);

    // ===== (Nouveau) Top employés TTC (uniquement rattachés)
    $byEmp = $this->db->fetchAllAssociative("
      SELECT COALESCE(x.employe_label,'(Non rattaché)') AS label, SUM(x.amount_cents) AS v
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY COALESCE(x.employe_label,'(Non rattaché)')
      ORDER BY v DESC
      LIMIT 10
    ", $params);

    // ===== (Nouveau) Empilé TTC par employé/fournisseur
    $byEmpProvAmount = $this->db->fetchAllAssociative("
      SELECT
        COALESCE(x.employe_label,'(Non rattaché)') AS label,
        SUM(CASE WHEN x.provider = 'alx' THEN x.amount_cents ELSE 0 END) AS alx,
        SUM(CASE WHEN x.provider = 'total' THEN x.amount_cents ELSE 0 END) AS total,
        SUM(CASE WHEN x.provider = 'edenred' THEN x.amount_cents ELSE 0 END) AS edenred,
        SUM(CASE WHEN x.provider = 'note' THEN x.amount_cents ELSE 0 END) AS note
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY COALESCE(x.employe_label,'(Non rattaché)')
      ORDER BY
        (
          SUM(CASE WHEN x.provider = 'alx' THEN x.amount_cents ELSE 0 END)
          + SUM(CASE WHEN x.provider = 'total' THEN x.amount_cents ELSE 0 END)
          + SUM(CASE WHEN x.provider = 'edenred' THEN x.amount_cents ELSE 0 END)
          + SUM(CASE WHEN x.provider = 'note' THEN x.amount_cents ELSE 0 END)
        ) DESC
      LIMIT 10
    ", $params);

    // ===== (Nouveau) Empilé QTY par employé/fournisseur
    $byEmpProvQty = $this->db->fetchAllAssociative("
      SELECT
        COALESCE(x.employe_label,'(Non rattaché)') AS label,
        SUM(CASE WHEN x.provider = 'alx' THEN x.qty ELSE 0 END) AS alx,
        SUM(CASE WHEN x.provider = 'total' THEN x.qty ELSE 0 END) AS total,
        SUM(CASE WHEN x.provider = 'edenred' THEN x.qty ELSE 0 END) AS edenred,
        SUM(CASE WHEN x.provider = 'note' THEN x.qty ELSE 0 END) AS note
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY COALESCE(x.employe_label,'(Non rattaché)')
      ORDER BY
        (
          SUM(CASE WHEN x.provider = 'alx' THEN x.qty ELSE 0 END)
          + SUM(CASE WHEN x.provider = 'total' THEN x.qty ELSE 0 END)
          + SUM(CASE WHEN x.provider = 'edenred' THEN x.qty ELSE 0 END)
          + SUM(CASE WHEN x.provider = 'note' THEN x.qty ELSE 0 END)
        ) DESC
      LIMIT 10
    ", $params);

    return [
      'kpis' => [
        'qty' => (float) ($k['qty'] ?? 0),
        'amount_cents' => (int) ($k['amount_cents'] ?? 0),
        'cnt' => (int) ($k['cnt'] ?? 0),
        'cnt_unmatched_engin' => (int) ($k['cnt_unmatched_engin'] ?? 0),
        'cnt_unmatched_employe' => (int) ($k['cnt_unmatched_employe'] ?? 0),
      ],
      'charts' => [
        'byProvider' => $byProv,
        'trendMonthly' => $trend,
        'topEngins' => $topEng,

        // ✅ compat (ton ancien nom)
        'topEmployes' => $byEmp,

        // ✅ nouveaux noms (pour tes nouveaux charts JS)
        'byEmployee' => $byEmp,
        'byEmployeeProviderAmount' => $byEmpProvAmount,
        'byEmployeeProviderQty' => $byEmpProvQty,
      ],
    ];
  }


  /**
   * Ajoute au SQL $where un filtre (IN (...) + option "Non catégorisé" => NULL/'')
   * $field doit être du type "x.produit_cat" ou "x.produit_sous"
   * $values = liste de valeurs enum (ex: ['carburant','route',...]) éventuellement avec "__NULL__"
   */
  private function addEnumFilterWithNull(
    string $field,
    string $paramPrefix,
    array $values,
    array &$params,
    string &$where
  ): void {
    if (empty($values) || !is_array($values)) return;

    $values = array_values(array_unique(array_map('strval', $values)));

    $wantNull = in_array('__NULL__', $values, true);
    $values = array_values(array_filter($values, fn($v) => $v !== '__NULL__' && $v !== ''));

    // rien à faire si on n'a ni valeurs ni null
    if ($values === [] && !$wantNull) return;

    $parts = [];

    if ($values !== []) {
      $in = [];
      foreach ($values as $i => $v) {
        $k = $paramPrefix . $i;
        $in[] = ':' . $k;
        $params[$k] = $v;
      }
      $parts[] = "$field IN (" . implode(',', $in) . ")";
    }

    if ($wantNull) {
      $parts[] = "($field IS NULL OR $field = '')";
    }

    // combine en OR, puis AND sur le where global
    $where .= " AND (" . implode(' OR ', $parts) . ") ";
  }

  /**
   * provider filters : accepte ['ALX','TOTAL','EDENRED'] / mixed / lowercase
   * et retourne ['alx','total','edenred'] (sans doublons)
   */
  private function normalizeProviders(array $providers): array
  {
    $allowed = ['alx', 'total', 'edenred', 'note'];

    $out = [];
    foreach ($providers as $p) {
      $p = strtolower(trim((string) $p));
      if ($p === '') {
        continue;
      }

      if (in_array($p, $allowed, true)) {
        $out[] = $p;
      }
    }

    return array_values(array_unique($out));
  }
  private function unionSql(): string
  {
    $C = self::COLL; // 'utf8mb4_unicode_ci'

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
    CONCAT(COALESCE(t.vehicule,''), ' - ', COALESCE(t.agent,'')) AS label,

    COALESCE(t.engin_id, ee.engin_id) AS engin_id,
    e.nom AS engin_label,

    COALESCE(t.utilisateur_id, ue.utilisateur_id) AS employe_id,
    CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,'')) AS employe_label,

    tde.produit_id AS produit_id,
    COALESCE(CONCAT(td.categorie_produit,' / ',td.sous_categorie_produit), '') AS produit_label,
    td.categorie_produit AS produit_cat,
    td.sous_categorie_produit AS produit_sous

  FROM transaction_carte_alx t

  LEFT JOIN engin_external_id ee
    ON ee.provider = 'alx'
    AND ee.active = 1
    AND ee.value COLLATE $C = t.vehicule COLLATE $C

  LEFT JOIN utilisateur_external_id ue
    ON ue.provider = 'alx'
    AND ue.active = 1
    AND ue.value COLLATE $C = t.agent COLLATE $C

  LEFT JOIN produit_external_id tde
    ON tde.provider = 'alx'
    AND tde.active = 1
    AND tde.value COLLATE $C = (CAST(t.cuve AS CHAR CHARACTER SET utf8mb4) COLLATE $C)

  LEFT JOIN produit td ON td.id = tde.produit_id

  LEFT JOIN engin e ON e.id = COALESCE(t.engin_id, ee.engin_id)
  LEFT JOIN utilisateur u ON u.id = COALESCE(t.utilisateur_id, ue.utilisateur_id)

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

    tde.produit_id AS produit_id,
    COALESCE(CONCAT(td.categorie_produit,' / ',td.sous_categorie_produit), '') AS produit_label,
    td.categorie_produit AS produit_cat,
    td.sous_categorie_produit AS produit_sous

  FROM transaction_carte_total t

  LEFT JOIN engin_external_id ee
    ON ee.provider = 'total'
    AND ee.active = 1
    AND ee.value COLLATE $C = t.nom_personnalise_carte COLLATE $C

  LEFT JOIN utilisateur_external_id ue
    ON ue.provider = 'total'
    AND ue.active = 1
    AND ue.value COLLATE $C = t.code_conducteur COLLATE $C

  LEFT JOIN produit_external_id tde
    ON tde.provider = 'total'
    AND tde.active = 1
    AND tde.value COLLATE $C = t.categorie_libelle_produit COLLATE $C

  LEFT JOIN produit td ON td.id = tde.produit_id

  LEFT JOIN engin e ON e.id = COALESCE(t.engin_id, ee.engin_id)
  LEFT JOIN utilisateur u ON u.id = COALESCE(t.utilisateur_id, ue.utilisateur_id)

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

    tde.produit_id AS produit_id,
    COALESCE(CONCAT(td.categorie_produit,' / ',td.sous_categorie_produit), '') AS produit_label,
    td.categorie_produit AS produit_cat,
    td.sous_categorie_produit AS produit_sous

  FROM transaction_carte_edenred t

  LEFT JOIN engin_external_id ee
    ON ee.provider = 'edenred'
    AND ee.active = 1
    AND ee.value COLLATE $C = (COALESCE(t.immatriculation, t.kilometrage) COLLATE $C)

  LEFT JOIN utilisateur_external_id ue
    ON ue.provider = 'edenred'
    AND ue.active = 1
    AND ue.value COLLATE $C = t.code_vehicule COLLATE $C

  LEFT JOIN produit_external_id tde
    ON tde.provider = 'edenred'
    AND tde.active = 1
    AND tde.value COLLATE $C = t.produit COLLATE $C

  LEFT JOIN produit td ON td.id = tde.produit_id

  LEFT JOIN engin e ON e.id = COALESCE(t.engin_id, ee.engin_id)
  LEFT JOIN utilisateur u ON u.id = COALESCE(t.utilisateur_id, ue.utilisateur_id)

  UNION ALL

  /* ===================== MANUAL / NOTES ===================== */
  SELECT
    'note' AS provider,
    n.id,
    n.entite_id,
    n.date_transaction AS date_tx,
    NULL AS carte,
    NULL AS veh_key,
    NULL AS site,
    COALESCE(CAST(n.quantite AS DECIMAL(12,3)),0) AS qty,
    CAST(ROUND(COALESCE(CAST(n.montant_ttc_eur AS DECIMAL(12,2)),0) * 100) AS SIGNED) AS amount_cents,
    TRIM(
      CONCAT(
        COALESCE(n.libelle, ''),
        CASE
          WHEN n.commentaire IS NOT NULL AND n.commentaire <> '' THEN CONCAT(' - ', n.commentaire)
          ELSE ''
        END
      )
    ) AS label,

    n.engin_id AS engin_id,
    e.nom AS engin_label,

    n.utilisateur_id AS employe_id,
    CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,'')) AS employe_label,

    n.produit_id AS produit_id,
    COALESCE(CONCAT(p.categorie_produit,' / ',p.sous_categorie_produit), '') AS produit_label,
    p.categorie_produit AS produit_cat,
    p.sous_categorie_produit AS produit_sous

  FROM note n
  LEFT JOIN engin e ON e.id = n.engin_id
  LEFT JOIN utilisateur u ON u.id = n.utilisateur_id
  LEFT JOIN produit p ON p.id = n.produit_id
  ";
  }



  /**
   * 1 event / jour avec breakdown provider (JSON) pour la modal.
   * @return array<int,array{day:string, amount_cents:int, cnt:int, qty:float, by_provider_json:string}>
   */
  public function calendarDaily(
    Entite $entite,
    array $f,
    string $rangeStart,
    string $rangeEnd,
    ?int $scopeEnginId,
    ?int $scopeEmployeId
  ): array {
    $sqlBase = $this->unionSql();

    $params = [
      'entite' => $entite->getId(),
      'rs' => $rangeStart,
      're' => $rangeEnd,
    ];

    // ⚠️ on va appliquer les mêmes filtres dans 2 sous-requêtes
    $where = " x.entite_id = :entite AND x.date_tx BETWEEN :rs AND :re ";

    // providers
    $providers = $this->normalizeProviders($f['providers'] ?? []);
    if ($providers !== []) {
      $in = [];
      foreach ($providers as $i => $p) {
        $k = 'p' . $i;
        $in[] = ':' . $k;
        $params[$k] = $p;
      }
      $where .= " AND x.provider IN (" . implode(',', $in) . ") ";
    }

    // ✅ Engins (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.engin_id',
      'engin',
      $f['enginIds'] ?? [],
      !empty($f['enginUncategorized']),
      $params,
      $where
    );

    // ✅ Employés (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.employe_id',
      'emp',
      $f['employeIds'] ?? [],
      !empty($f['employeUncategorized']),
      $params,
      $where
    );

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

    // libellé
    // ✅ Catégories produit (multi)
    $this->addEnumFilterWithNull('x.produit_cat', 'cat', $f['categorieProduits'] ?? [], $params, $where);
    $this->addEnumFilterWithNull('x.produit_sous', 'sous', $f['sousCategorieProduits'] ?? [], $params, $where);

    // scope page (engin/employe)
    if ($scopeEnginId) {
      $where .= " AND x.engin_id = :scopeEnginId ";
      $params['scopeEnginId'] = $scopeEnginId;
    }
    if ($scopeEmployeId) {
      $where .= " AND x.employe_id = :scopeEmployeId ";
      $params['scopeEmployeId'] = $scopeEmployeId;
    }

    /**
     * ✅ Stratégie:
     * 1) base = unionSql filtré
     * 2) d = group by day pour total/day
     * 3) p = group by day, provider pour breakdown
     * 4) join p->d puis group by day et GROUP_CONCAT d'une string JSON
     */
    $sql = "
    SELECT
      d.day AS day,
      d.amount_cents AS amount_cents,
      d.cnt AS cnt,
      d.qty AS qty,

      CONCAT(
        '[',
        COALESCE(
          GROUP_CONCAT(
            CONCAT(
              '{\"provider\":\"', p.provider,
              '\",\"amount_cents\":', p.amount_cents,
              ',\"cnt\":', p.cnt,
              ',\"qty\":', p.qty,
              '}'
            )
            ORDER BY p.amount_cents DESC
            SEPARATOR ','
          ),
          ''
        ),
        ']'
      ) AS by_provider_json

    FROM (
      SELECT
        DATE(x.date_tx) AS day,
        COALESCE(SUM(x.amount_cents),0) AS amount_cents,
        COUNT(*) AS cnt,
        COALESCE(SUM(x.qty),0) AS qty
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY DATE(x.date_tx)
    ) d

    LEFT JOIN (
      SELECT
        DATE(x.date_tx) AS day,
        UPPER(x.provider) AS provider,
        COALESCE(SUM(x.amount_cents),0) AS amount_cents,
        COUNT(*) AS cnt,
        COALESCE(SUM(x.qty),0) AS qty
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY DATE(x.date_tx), UPPER(x.provider)
    ) p
      ON p.day = d.day

    GROUP BY d.day, d.amount_cents, d.cnt, d.qty
    ORDER BY d.day ASC
  ";

    return $this->db->fetchAllAssociative($sql, $params);
  }
  /**
   * Totaux pour badges (montant/tx/qty) sur le range visible.
   * @return array{amount_cents:int,cnt:int,qty:float}
   */
  public function calendarTotals(
    Entite $entite,
    array $f,
    string $rangeStart,
    string $rangeEnd,
    ?int $scopeEnginId,
    ?int $scopeEmployeId
  ): array {
    $sqlBase = $this->unionSql();

    $params = [
      'entite' => $entite->getId(),
      'rs' => $rangeStart,
      're' => $rangeEnd,
    ];

    $where = " x.entite_id = :entite AND x.date_tx BETWEEN :rs AND :re ";

    $providers = $this->normalizeProviders($f['providers'] ?? []);
    if ($providers !== []) {
      $in = [];
      foreach ($providers as $i => $p) {
        $k = 'p' . $i;
        $in[] = ':' . $k;
        $params[$k] = $p;
      }
      $where .= " AND x.provider IN (" . implode(',', $in) . ") ";
    }

    // ✅ Engins (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.engin_id',
      'engin',
      $f['enginIds'] ?? [],
      !empty($f['enginUncategorized']),
      $params,
      $where
    );

    // ✅ Employés (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.employe_id',
      'emp',
      $f['employeIds'] ?? [],
      !empty($f['employeUncategorized']),
      $params,
      $where
    );

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
    $this->addEnumFilterWithNull('x.produit_cat', 'cat', $f['categorieProduits'] ?? [], $params, $where);
    $this->addEnumFilterWithNull('x.produit_sous', 'sous', $f['sousCategorieProduits'] ?? [], $params, $where);

    if ($scopeEnginId) {
      $where .= " AND x.engin_id = :scopeEnginId ";
      $params['scopeEnginId'] = $scopeEnginId;
    }
    if ($scopeEmployeId) {
      $where .= " AND x.employe_id = :scopeEmployeId ";
      $params['scopeEmployeId'] = $scopeEmployeId;
    }

    $sql = "
      SELECT
        COALESCE(SUM(x.amount_cents),0) AS amount_cents,
        COUNT(*) AS cnt,
        COALESCE(SUM(x.qty),0) AS qty
      FROM ($sqlBase) x
      WHERE $where
    ";

    $r = $this->db->fetchAssociative($sql, $params) ?: [];
    return [
      'amount_cents' => (int)($r['amount_cents'] ?? 0),
      'cnt' => (int)($r['cnt'] ?? 0),
      'qty' => (float)($r['qty'] ?? 0),
    ];
  }


  /**
   * Filtre IDs + option "Non catégorisé" => field IS NULL
   * Exemple: addIdFilterWithNull('x.engin_id','engin',$ids,true,...)
   */
  private function addIdFilterWithNull(
    string $field,
    string $paramPrefix,
    array $ids,
    bool $wantNull,
    array &$params,
    string &$where
  ): void {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));

    if ($ids === [] && !$wantNull) return;

    $parts = [];

    if ($ids !== []) {
      $in = [];
      foreach ($ids as $i => $id) {
        $k = $paramPrefix . $i;
        $in[] = ':' . $k;
        $params[$k] = (int) $id;
      }
      $parts[] = "$field IN (" . implode(',', $in) . ")";
    }

    if ($wantNull) {
      $parts[] = "($field IS NULL)";
    }

    $where .= " AND (" . implode(' OR ', $parts) . ") ";
  }

  /**
   * Détails modal (un jour) + breakdown provider + lignes.
   * @return array{summary:array<string,mixed>,byProvider:array<int,array<string,mixed>>,rows:array<int,array<string,mixed>>}
   */
  public function calendarDayDetails(
    Entite $entite,
    array $f,
    string $dayStart,
    string $dayEnd,
    ?int $scopeEnginId,
    ?int $scopeEmployeId,
    int $limit = 200
  ): array {
    $sqlBase = $this->unionSql();

    $limit = max(20, min(600, $limit));

    $params = [
      'entite' => $entite->getId(),
      'ds' => $dayStart,
      'de' => $dayEnd,
    ];

    $where = " x.entite_id = :entite AND x.date_tx BETWEEN :ds AND :de ";

    $providers = $this->normalizeProviders($f['providers'] ?? []);
    if ($providers !== []) {
      $in = [];
      foreach ($providers as $i => $p) {
        $k = 'p' . $i;
        $in[] = ':' . $k;
        $params[$k] = $p;
      }
      $where .= " AND x.provider IN (" . implode(',', $in) . ") ";
    }

    // ✅ Engins (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.engin_id',
      'engin',
      $f['enginIds'] ?? [],
      !empty($f['enginUncategorized']),
      $params,
      $where
    );

    // ✅ Employés (multi) + Non catégorisé
    $this->addIdFilterWithNull(
      'x.employe_id',
      'emp',
      $f['employeIds'] ?? [],
      !empty($f['employeUncategorized']),
      $params,
      $where
    );

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

    // libellé
    // ✅ Catégories produit (multi)
    $this->addEnumFilterWithNull('x.produit_cat', 'cat', $f['categorieProduits'] ?? [], $params, $where);
    $this->addEnumFilterWithNull('x.produit_sous', 'sous', $f['sousCategorieProduits'] ?? [], $params, $where);

    if ($scopeEnginId) {
      $where .= " AND x.engin_id = :scopeEnginId ";
      $params['scopeEnginId'] = $scopeEnginId;
    }
    if ($scopeEmployeId) {
      $where .= " AND x.employe_id = :scopeEmployeId ";
      $params['scopeEmployeId'] = $scopeEmployeId;
    }

    $summary = $this->db->fetchAssociative("
      SELECT
        COALESCE(SUM(x.amount_cents),0) AS amount_cents,
        COUNT(*) AS cnt,
        COALESCE(SUM(x.qty),0) AS qty
      FROM ($sqlBase) x
      WHERE $where
    ", $params) ?: [];

    $byProvider = $this->db->fetchAllAssociative("
      SELECT
        UPPER(x.provider) AS provider,
        COALESCE(SUM(x.amount_cents),0) AS amount_cents,
        COUNT(*) AS cnt,
        COALESCE(SUM(x.qty),0) AS qty
      FROM ($sqlBase) x
      WHERE $where
      GROUP BY x.provider
      ORDER BY amount_cents DESC
    ", $params);

    $rows = $this->db->fetchAllAssociative("
      SELECT
        x.date_tx,
        UPPER(x.provider) AS provider,
        x.engin_label,
        x.employe_label,
        x.site,
        x.carte,
        x.veh_key,
        x.qty,
        x.amount_cents,
        x.label
      FROM ($sqlBase) x
      WHERE $where
      ORDER BY x.amount_cents DESC, x.provider ASC, x.id DESC
      LIMIT $limit
    ", $params);

    return [
      'summary' => [
        'amount_cents' => (int)($summary['amount_cents'] ?? 0),
        'cnt' => (int)($summary['cnt'] ?? 0),
        'qty' => (float)($summary['qty'] ?? 0),
      ],
      'byProvider' => $byProvider,
      'rows' => $rows,
    ];
  }


  private function addEnumFilterWithNullFlag(
    string $field,
    string $paramPrefix,
    array $values,
    bool $wantNull,
    array &$params,
    string &$where
  ): void {
    $values = array_values(array_unique(array_filter(array_map('strval', $values), fn($v) => $v !== '')));

    if ($values === [] && !$wantNull) {
      return;
    }

    $parts = [];

    if ($values !== []) {
      $in = [];
      foreach ($values as $i => $v) {
        $k = $paramPrefix . $i;
        $in[] = ':' . $k;
        $params[$k] = $v;
      }
      $parts[] = "$field IN (" . implode(',', $in) . ")";
    }

    if ($wantNull) {
      $parts[] = "($field IS NULL OR $field = '')";
    }

    $where .= " AND (" . implode(' OR ', $parts) . ") ";
  }
}
