<?php
// src/Repository/GeoAddressCacheBackfillRepository.php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class GeoAddressCacheBackfillRepository
{
  public function __construct(private readonly Connection $db) {}

  /**
   * @return int nb lignes insérées
   */
  public function backfillDistinctAddresses(int $entiteId, ?string $alxFixedAddress = null): int
  {
    $params = ['entite' => $entiteId];

    // ✅ ALX doit retourner EXACTEMENT la même structure (1 colonne: address)
    $alxSql = '';
    if ($alxFixedAddress) {
      $params['alx'] = $alxFixedAddress;
      $alxSql = "UNION ALL SELECT :alx AS address";
    }

    $sql = "
            INSERT INTO geo_address_cache (entite_id, addr_hash, address, provider, geocoded_at, lat, lng, display_name, confidence)
            SELECT
              :entite AS entite_id,
              SHA2(x.addr_norm, 256) AS addr_hash,
              x.address AS address,
              NULL AS provider,
              NULL AS geocoded_at,
              NULL AS lat,
              NULL AS lng,
              NULL AS display_name,
              NULL AS confidence
            FROM (
              SELECT DISTINCT
                address,
                TRIM(LOWER(
                  REGEXP_REPLACE(
                    REGEXP_REPLACE(address, '\\\\s+', ' '),
                    '\\\\s*,\\\\s*', ', '
                  )
                )) AS addr_norm
              FROM (
                /* -------- TOTAL -------- */
                SELECT
                  TRIM(CONCAT(
                    COALESCE(t.adresse,''),
                    CASE WHEN t.code_postal IS NULL OR t.code_postal='' THEN '' ELSE CONCAT(', ', t.code_postal) END,
                    CASE WHEN t.ville IS NULL OR t.ville='' THEN '' ELSE CONCAT(' ', t.ville) END,
                    CASE WHEN t.pays IS NULL OR t.pays='' THEN '' ELSE CONCAT(', ', t.pays) END
                  )) AS address
                FROM transaction_carte_total t
                WHERE t.entite_id = :entite
                  AND t.adresse IS NOT NULL AND TRIM(t.adresse) <> ''

                UNION ALL

                /* -------- EDENRED (best effort) -------- */
                SELECT
                  TRIM(CONCAT(
                    COALESCE(ed.site_libelle,''),
                    CASE WHEN ed.site_libelle_court IS NULL OR ed.site_libelle_court='' THEN '' ELSE CONCAT(', ', ed.site_libelle_court) END,
                    CASE WHEN ed.enseigne IS NULL OR ed.enseigne='' THEN '' ELSE CONCAT(', ', ed.enseigne) END,
                    ', France'
                  )) AS address
                FROM transaction_carte_edenred ed
                WHERE ed.entite_id = :entite
                  AND (
                    (ed.site_libelle IS NOT NULL AND TRIM(ed.site_libelle) <> '')
                    OR (ed.site_libelle_court IS NOT NULL AND TRIM(ed.site_libelle_court) <> '')
                    OR (ed.enseigne IS NOT NULL AND TRIM(ed.enseigne) <> '')
                  )

                /* -------- ALX : adresse fixe injectée -------- */
                $alxSql
              ) a
              WHERE address IS NOT NULL AND TRIM(address) <> ''
            ) x
            WHERE x.addr_norm <> ''
            ON DUPLICATE KEY UPDATE
              address = VALUES(address)
        ";

    return $this->db->executeStatement($sql, $params);
  }
}
