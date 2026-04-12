<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entite;
use Doctrine\DBAL\Connection;

final class FuelMapRepository
{
  public function __construct(private readonly Connection $db) {}

  /**
   * Retourne une liste de lieux géocodés + sample de transactions (max N par lieu).
   *
   * @return array<int,array<string,mixed>>
   */
  public function fetchPlacesWithSamples(
    Entite $entite,
    array $f,
    int $maxPlaces = 500,
    int $samplePerPlace = 8
  ): array {
    // ⚠️ on réutilise exactement tes flags (providersNone, enginNone, employeNone, etc.)
    $params = [
      'entite' => $entite->getId(),
      'ds' => $f['dateStart'],
      'de' => $f['dateEnd'],
      'alx_addr' => '2 Rue des Orgueillous, 34270 Saint-Mathieu-de-Tréviers, France',
      'maxPlaces' => $maxPlaces,
    ];

    $params['ds_dt'] = $f['dateStart'] . ' 00:00:00';
    $params['de_dt'] = (new \DateTimeImmutable($f['dateEnd']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    unset($params['ds'], $params['de']); // si tu veux être clean

    // Helpers: build WHERE fragments from filters
    $whereCommon = " x.entite_id = :entite AND x.dt >= :ds_dt AND x.dt < :de_dt ";

    // Providers
    if (!empty($f['providersNone'])) {
      // aucun fournisseur => aucun résultat
      return [];
    }
    if (!empty($f['providers']) && is_array($f['providers']) && count($f['providers']) > 0) {
      $in = [];
      foreach ($f['providers'] as $i => $p) {
        $k = 'prov' . $i;
        $in[] = ':' . $k;
        $params[$k] = $p;
      }
      $whereCommon .= " AND x.provider IN (" . implode(',', $in) . ") ";
    }

    // Engins
    if (!empty($f['enginNone'])) return [];
    $enginIds = $f['enginIds'] ?? [];
    $enginUncat = !empty($f['enginUncategorized']);

    if (is_array($enginIds) && count($enginIds) > 0) {
      $in = [];
      foreach ($enginIds as $i => $id) {
        $k = 'eid' . $i;
        $in[] = ':' . $k;
        $params[$k] = (int)$id;
      }
      if ($enginUncat) {
        $whereCommon .= " AND (x.engin_id IN (" . implode(',', $in) . ") OR x.engin_id IS NULL) ";
      } else {
        $whereCommon .= " AND x.engin_id IN (" . implode(',', $in) . ") ";
      }
    } else {
      // pas de filtre enginIds => si enginUncategorized=1 et "allSelected" côté UI est faux, on veut uniquement NULL
      if ($enginUncat) {
        $whereCommon .= " AND x.engin_id IS NULL ";
      }
    }

    // Employés
    if (!empty($f['employeNone'])) return [];
    $empIds = $f['employeIds'] ?? [];
    $empUncat = !empty($f['employeUncategorized']);

    if (is_array($empIds) && count($empIds) > 0) {
      $in = [];
      foreach ($empIds as $i => $id) {
        $k = 'uid' . $i;
        $in[] = ':' . $k;
        $params[$k] = (int)$id;
      }
      if ($empUncat) {
        $whereCommon .= " AND (x.utilisateur_id IN (" . implode(',', $in) . ") OR x.utilisateur_id IS NULL) ";
      } else {
        $whereCommon .= " AND x.utilisateur_id IN (" . implode(',', $in) . ") ";
      }
    } else {
      if ($empUncat) {
        $whereCommon .= " AND x.utilisateur_id IS NULL ";
      }
    }

    // Catégories / sous-catégories : on filtre sur le flux unifié (si tu les as déjà dans ton dashboard)
    if (!empty($f['categorieNone'])) return [];
    if (!empty($f['categorieProduits']) && is_array($f['categorieProduits']) && count($f['categorieProduits']) > 0) {
      $in = [];
      foreach ($f['categorieProduits'] as $i => $v) {
        $k = 'cat' . $i;
        $in[] = ':' . $k;
        $params[$k] = $v;
      }
      $whereCommon .= " AND x.categorie IN (" . implode(',', $in) . ") ";
    }

    if (!empty($f['sousCategorieNone'])) return [];
    if (!empty($f['sousCategorieProduits']) && is_array($f['sousCategorieProduits']) && count($f['sousCategorieProduits']) > 0) {
      $in = [];
      foreach ($f['sousCategorieProduits'] as $i => $v) {
        $k = 'sous' . $i;
        $in[] = ':' . $k;
        $params[$k] = $v;
      }
      $whereCommon .= " AND x.sous_categorie IN (" . implode(',', $in) . ") ";
    }

    /**
     * On unifie les 3 tables dans un flux `x`
     * - dt: datetime
     * - provider: ALX/TOTAL/EDENRED
     * - address_norm: adresse normalisée (déjà "propre" dans SQL)
     * - engin_id, utilisateur_id
     * - engin_label / employe_label (pour popup)
     */
    $sql = "
WITH unified AS (
  -- ALX (adresse fixe)
  SELECT
    a.entite_id,
    TIMESTAMP(a.journee, COALESCE(a.horaire, '00:00:00')) AS dt,
    'ALX' AS provider,
    TRIM(:alx_addr) AS address_raw,
    a.engin_id,
    a.utilisateur_id,
    COALESCE(e.nom, NULL) AS engin_label,
    NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.prenom,''), NULLIF(u.nom,''))), '') AS employe_label,
    NULL AS label,
    NULL AS categorie,
    NULL AS sous_categorie,
    CAST(a.quantite AS DECIMAL(12,3)) AS qty,
    -- si tu as le montant ailleurs pour ALX, remplace ici
    NULL AS amount_cents
  FROM transaction_carte_alx a
  LEFT JOIN engin e ON e.id = a.engin_id
  LEFT JOIN utilisateur u ON u.id = a.utilisateur_id
  WHERE a.entite_id = :entite

  UNION ALL

      UNION ALL

  -- TOTAL (adresse + cp + ville + pays) + mapping external_id
  SELECT
  t.entite_id,
  TIMESTAMP(t.date_transaction, COALESCE(t.heure_transaction,'00:00:00')) AS dt,
  'TOTAL' AS provider,
  TRIM(CONCAT_WS(', ',
    NULLIF(t.adresse,''),
    NULLIF(t.code_postal,''),
    NULLIF(t.ville,''),
    NULLIF(t.pays,'')
  )) AS address_raw,

  COALESCE(t.engin_id, ee.engin_id) AS engin_id,
  COALESCE(t.utilisateur_id, ue.utilisateur_id) AS utilisateur_id,

  COALESCE(e2.nom, e.nom, NULL) AS engin_label,

  COALESCE(
    NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u2.prenom,''), NULLIF(u2.nom,''))), ''),
    NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.prenom,''), NULLIF(u.nom,''))), ''),
    NULLIF(TRIM(CONCAT_WS(' ', NULLIF(t.prenom_collaborateur,''), NULLIF(t.nom_collaborateur,''))), '')
  ) AS employe_label,

  t.produit AS label,
  t.categorie_libelle_produit AS categorie,
  NULL AS sous_categorie,
  CAST(t.quantite AS DECIMAL(12,3)) AS qty,
  ROUND(COALESCE(t.montant_ttc_eur,0) * 100) AS amount_cents

FROM transaction_carte_total t

LEFT JOIN engin_external_id ee
  ON LOWER(ee.provider) = 'total'
 AND ee.active = 1
 AND ee.value = UPPER(
   REPLACE(REPLACE(REPLACE(REPLACE(TRIM(NULLIF(t.immatriculation_vehicule,'')), ' ', ''), '-', ''), '.', ''), '/', '')
 )

LEFT JOIN utilisateur_external_id ue
  ON LOWER(ue.provider) = 'total'
 AND ue.active = 1
 AND ue.value = UPPER(
   REPLACE(REPLACE(REPLACE(REPLACE(TRIM(NULLIF(t.code_conducteur,'')), ' ', ''), '-', ''), '.', ''), '/', '')
 )

LEFT JOIN engin e ON e.id = t.engin_id
LEFT JOIN utilisateur u ON u.id = t.utilisateur_id

-- ⚠️ le filtre entite est ICI sur e2, pas dans le ON de ee
LEFT JOIN engin e2 ON e2.id = ee.engin_id AND e2.entite_id = t.entite_id
LEFT JOIN utilisateur u2 ON u2.id = ue.utilisateur_id

WHERE t.entite_id = :entite

  UNION ALL

  -- EDENRED (fallback sur siteLibelle / siteLibelleCourt / enseigne)
  SELECT
    ed.entite_id,
    ed.date_transaction AS dt,
    'EDENRED' AS provider,
    TRIM(COALESCE(NULLIF(ed.site_libelle,''), NULLIF(ed.site_libelle_court,''), NULLIF(ed.enseigne,''))) AS address_raw,
    ed.engin_id,
    ed.utilisateur_id,
    COALESCE(e.nom, NULL) AS engin_label,
    NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.prenom,''), NULLIF(u.nom,''))), '') AS employe_label,
    ed.produit AS label,
    NULL AS categorie,
    NULL AS sous_categorie,
    CAST(ed.quantite AS DECIMAL(12,3)) AS qty,
    ROUND(COALESCE(ed.montant_ttc,0) * 100) AS amount_cents
  FROM transaction_carte_edenred ed
  LEFT JOIN engin e ON e.id = ed.engin_id
  LEFT JOIN utilisateur u ON u.id = ed.utilisateur_id
  WHERE ed.entite_id = :entite
),
x AS (
  SELECT
    entite_id,
    dt,
    provider,
    -- normalize light côté SQL (vrai normalizer PHP côté cache)
    TRIM(REPLACE(REPLACE(REPLACE(address_raw, '  ', ' '), '  ', ' '), ';',' ')) AS address_norm,
    engin_id,
    utilisateur_id,
    engin_label,
    employe_label,
    label,
    categorie,
    sous_categorie,
    qty,
    amount_cents
  FROM unified
  WHERE address_raw IS NOT NULL AND TRIM(address_raw) <> ''
)
SELECT
  g.id AS place_id,
  g.lat,
  g.lng,
  g.address AS address,
  COUNT(*) AS cnt,
  COALESCE(SUM(COALESCE(x.amount_cents,0)),0) AS amount_cents,
  MIN(x.dt) AS first_dt,
  MAX(x.dt) AS last_dt
FROM x
JOIN geo_address_cache g
  ON g.entite_id = x.entite_id
 AND g.address = LOWER(x.address_norm)
WHERE {$whereCommon}
  AND g.lat IS NOT NULL AND g.lng IS NOT NULL
GROUP BY g.id, g.lat, g.lng, g.address
ORDER BY last_dt DESC
LIMIT :maxPlaces
";

    $rows = $this->db->fetchAllAssociative($sql, $params);

    if (!$rows) return [];

    // On récupère ensuite un sample de transactions par place (popup premium)
    // (Query 2: transactions for these place_ids)
    $placeIds = array_map(fn($r) => (int)$r['place_id'], $rows);
    $in = [];
    foreach ($placeIds as $i => $id) {
      $k = 'pid' . $i;
      $in[] = ':' . $k;
      $params[$k] = $id;
    }
    $params['sampleN'] = $samplePerPlace;

    $sql2 = "
    WITH unified AS (
      SELECT
        a.entite_id,
        TIMESTAMP(a.journee, COALESCE(a.horaire, '00:00:00')) AS dt,
        'ALX' AS provider,
        LOWER(TRIM(:alx_addr)) AS address_norm,
        a.engin_id,
        a.utilisateur_id,
        COALESCE(e.nom, NULL) AS engin_label,
        NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.prenom,''), NULLIF(u.nom,''))), '') AS employe_label,
        NULL AS label,
        NULL AS categorie,
        NULL AS sous_categorie,
        CAST(a.quantite AS DECIMAL(12,3)) AS qty,
        NULL AS amount_cents
      FROM transaction_carte_alx a
      LEFT JOIN engin e ON e.id = a.engin_id
      LEFT JOIN utilisateur u ON u.id = a.utilisateur_id
      WHERE a.entite_id = :entite

      UNION ALL

      /* ---------- TOTAL ---------- */
      SELECT
        'TOTAL' AS provider,
        t.entite_id,
        t.date_transaction AS dt,

        COALESCE(t.engin_id, ee.engin_id) AS engin_id,
        COALESCE(t.utilisateur_id, ue.utilisateur_id) AS utilisateur_id,

        COALESCE(e2.nom, e.nom) AS engin_label,

        COALESCE(
          NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u2.prenom,''), NULLIF(u2.nom,''))), ''),
          NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.prenom,''), NULLIF(u.nom,''))), ''),
          NULLIF(TRIM(CONCAT_WS(' ', NULLIF(t.prenom_collaborateur,''), NULLIF(t.nom_collaborateur,''))), '')
        ) AS employe_label,

        COALESCE(t.produit, t.categorie_libelle_produit) AS label,
        CASE
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'gnr|gasoil|diesel|essence|ethanol|e85|adblue'
            OR LOWER(COALESCE(t.categorie_libelle_produit,'')) REGEXP 'carburant|energie'
            THEN 'carburant'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'péage|peage|parking'
            THEN 'route'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'huile|lubr|lave|lavage|entretien'
            THEN 'entretien'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'accessoire'
            THEN 'accessoire'
          ELSE 'divers'
        END AS categorie,
        CASE
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'gnr' THEN 'gnr'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'adblue' THEN 'adblue'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'ethanol|e85' THEN 'ethanol'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'essence' THEN 'essence'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'gasoil|diesel' THEN 'gasoil'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'péage|peage' THEN 'peage'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'parking' THEN 'parking'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'huile|lubr' THEN 'lubrifiant'
          WHEN LOWER(COALESCE(t.produit,'')) REGEXP 'lavage|lave' THEN 'lavage'
          ELSE NULL
        END AS sous_categorie,

        CAST(t.quantite AS DECIMAL(12,3)) AS qty,
        CAST(ROUND(COALESCE(t.montant_ttc_eur,0)*100,0) AS SIGNED) AS amount_cents,

        TRIM(CONCAT_WS(', ',
          NULLIF(t.adresse,''),
          NULLIF(t.code_postal,''),
          NULLIF(t.ville,''),
          NULLIF(t.pays,'')
        )) AS address

      FROM transaction_carte_total t

      LEFT JOIN engin_external_id ee
        ON LOWER(ee.provider) = 'total'
      AND ee.active = 1
      AND ee.value = UPPER(REPLACE(REPLACE(REPLACE(REPLACE(
          TRIM(NULLIF(t.immatriculation_vehicule,'')), ' ',''),'-',''),'.',''),'/',''))

      LEFT JOIN utilisateur_external_id ue
        ON LOWER(ue.provider) = 'total'
      AND ue.active = 1
      AND ue.value = UPPER(REPLACE(REPLACE(REPLACE(REPLACE(
          TRIM(NULLIF(t.code_conducteur,'')), ' ',''),'-',''),'.',''),'/',''))

      LEFT JOIN engin e     ON e.id  = t.engin_id
      LEFT JOIN utilisateur u ON u.id = t.utilisateur_id
      LEFT JOIN engin e2    ON e2.id = ee.engin_id AND e2.entite_id = t.entite_id
      LEFT JOIN utilisateur u2 ON u2.id = ue.utilisateur_id

      WHERE t.entite_id = :entite
        AND t.date_transaction BETWEEN :ds AND :de

      UNION ALL

      SELECT
        ed.entite_id,
        ed.date_transaction AS dt,
        'EDENRED' AS provider,
        LOWER(TRIM(COALESCE(NULLIF(ed.site_libelle,''), NULLIF(ed.site_libelle_court,''), NULLIF(ed.enseigne,'')))) AS address_norm,
        ed.engin_id,
        ed.utilisateur_id,
        COALESCE(e.nom, NULL) AS engin_label,
        NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.prenom,''), NULLIF(u.nom,''))), '') AS employe_label,
        ed.produit AS label,
        NULL AS categorie,
        NULL AS sous_categorie,
        CAST(ed.quantite AS DECIMAL(12,3)) AS qty,
        ROUND(COALESCE(ed.montant_ttc,0) * 100) AS amount_cents
      FROM transaction_carte_edenred ed
      LEFT JOIN engin e ON e.id = ed.engin_id
      LEFT JOIN utilisateur u ON u.id = ed.utilisateur_id
      WHERE ed.entite_id = :entite
    ),
    x AS (
      SELECT
        entite_id, dt, provider, address_norm,
        engin_id, utilisateur_id, engin_label, employe_label,
        label, categorie, sous_categorie, qty, amount_cents
      FROM unified
      WHERE address_norm IS NOT NULL AND TRIM(address_norm) <> ''
    ),
    ranked AS (
      SELECT
        g.id AS place_id,
        x.dt, x.provider,
        x.engin_label, x.employe_label,
        x.label, x.categorie, x.sous_categorie,
        x.qty, x.amount_cents,
        ROW_NUMBER() OVER (PARTITION BY g.id ORDER BY x.dt DESC) AS rn
      FROM x
      JOIN geo_address_cache g
        ON g.entite_id = x.entite_id AND g.address = x.address_norm
      WHERE g.id IN (" . implode(',', $in) . ")
        AND {$whereCommon}
    )
    SELECT *
    FROM ranked
    WHERE rn <= :sampleN
    ORDER BY place_id, dt DESC
    ";

    $samples = $this->db->fetchAllAssociative($sql2, $params);

    $byPlace = [];
    foreach ($samples as $s) {
      $pid = (int)$s['place_id'];
      $byPlace[$pid] ??= [];
      $byPlace[$pid][] = [
        'dt' => (string)$s['dt'],
        'provider' => (string)$s['provider'],
        'engin' => $s['engin_label'] ?: null,
        'employe' => $s['employe_label'] ?: null,
        'label' => $s['label'] ?: null,
        'categorie' => $s['categorie'] ?: null,
        'sousCategorie' => $s['sous_categorie'] ?: null,
        'qty' => $s['qty'] !== null ? (float)$s['qty'] : null,
        'amount_cents' => $s['amount_cents'] !== null ? (int)$s['amount_cents'] : 0,
      ];
    }

    foreach ($rows as &$r) {
      $pid = (int)$r['place_id'];
      $r['sample'] = $byPlace[$pid] ?? [];
      $r['place_id'] = $pid;
      $r['lat'] = (float)$r['lat'];
      $r['lng'] = (float)$r['lng'];
      $r['cnt'] = (int)$r['cnt'];
      $r['amount_cents'] = (int)$r['amount_cents'];
    }

    return $rows;
  }
}
