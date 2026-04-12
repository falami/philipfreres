<?php
// src/Controller/Administrateur/FuelMapController.php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Repository\EnginRepository;
use App\Repository\UtilisateurRepository;
use App\Security\Permission\TenantPermission;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/administrateur/{entite}/carburant', name: 'app_administrateur_fuel_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class FuelMapController extends AbstractController
{
  /** Adresse fixe ALX (selon ton besoin) */
  private const ALX_FIXED_ADDRESS = '2 Rue des Orgueillous, 34270 Saint-Mathieu-de-Tréviers, France';

  public function __construct(
    private readonly Connection $db,
    private readonly EnginRepository $enginRepo,
    private readonly UtilisateurRepository $userRepo,
    private readonly CacheInterface $cache,
    private readonly HttpClientInterface $httpClient,
  ) {}

  // ------------------------------------------------------------
  // PAGE
  // ------------------------------------------------------------
  #[Route('/map', name: 'map', methods: ['GET'])]
  public function map(Entite $entite, Request $request): Response
  {
    $today = new \DateTimeImmutable('today');
    $start = $request->query->get('start') ?: $today->modify('-30 days')->format('Y-m-d');
    $end   = $request->query->get('end')   ?: $today->format('Y-m-d');

    $engins = $this->enginRepo->findBy(['entite' => $entite], ['nom' => 'ASC']);

    $employes = method_exists($this->userRepo, 'findEmployesForEntite')
      ? $this->userRepo->findEmployesForEntite($entite)
      : $this->userRepo->findBy([], ['nom' => 'ASC']);

    // ✅ ICI le fix
    $categorieProduits = \App\Enum\CategorieProduit::cases();
    $sousCategorieProduits = \App\Enum\SousCategorieProduit::cases();

    return $this->render('administrateur/carburant/map.html.twig', [
      'entite' => $entite,
      'start' => $start,
      'end' => $end,
      'engins' => $engins,
      'employes' => $employes,
      'categorieProduits' => $categorieProduits,
      'sousCategorieProduits' => $sousCategorieProduits,
    ]);
  }


  // ------------------------------------------------------------
  // API : points carte
  // ------------------------------------------------------------
  #[Route('/api/map-places', name: 'api_map_places', methods: ['GET'])]
  public function apiMapPlaces(Entite $entite, Request $request): JsonResponse
  {
    $f = $this->readFilters($request);

    // 1) Récupérer transactions "map-friendly" (avec une adresse exploitable)
    $tx = $this->fetchTxForMap($entite, $f);

    // 2) Regrouper par adresse normalisée + agrégation
    $groups = [];
    foreach ($tx as $row) {
      $address = trim((string)($row['address'] ?? ''));
      if ($address === '') {
        continue;
      }

      $key = $this->normalizeAddress($address);
      if (!isset($groups[$key])) {
        $groups[$key] = [
          'address' => $address,
          'cnt' => 0,
          'amount_cents' => 0,
          'sample' => [],
        ];
      }

      $groups[$key]['cnt']++;
      $groups[$key]['amount_cents'] += (int)($row['amount_cents'] ?? 0);

      // sample : max 8 entrées (pour popup)
      if (\count($groups[$key]['sample']) < 8) {
        $groups[$key]['sample'][] = [
          'provider' => $row['provider'] ?? null,
          'dt' => $row['dt'] ?? null, // ISO-ish
          'employe' => $row['employe'] ?? null,
          'engin' => $row['engin'] ?? null,
          'label' => $row['label'] ?? null,
          'categorie' => $row['categorie'] ?? null,
          'sousCategorie' => $row['sousCategorie'] ?? null,
          'qty' => $row['qty'] ?? null,
          'amount_cents' => (int)($row['amount_cents'] ?? 0),
        ];
      }
    }

    $maxGeocodes = 10; // géocodage "nouveau" par requête
    $geocodedNow = 0;
    $pending = 0;

    $places = [];

    /** 1) Prépare les hashes */
    $hashes = [];
    foreach ($groups as $g) {
      $clean = $this->cleanupForGeocode($g['address']);
      if ($clean === '') continue;
      $hashes[] = $this->addrHash($entite, $clean);
    }
    $hashes = array_values(array_unique($hashes));

    /** 2) Charge le cache DB en une fois */
    $geoCache = $this->fetchGeoCache($entite, $hashes);

    /** 3) Ajoute tous les points déjà en cache (illimité) */
    $missing = [];
    foreach ($groups as $g) {
      $clean = $this->cleanupForGeocode($g['address']);
      if ($clean === '') continue;

      $hash = $this->addrHash($entite, $clean);

      if (isset($geoCache[$hash])) {
        $geo = $geoCache[$hash];
        $places[] = [
          'address' => $g['address'],
          'lat' => $geo['lat'],
          'lng' => $geo['lng'],
          'cnt' => $g['cnt'],
          'amount_cents' => $g['amount_cents'],
          'sample' => $g['sample'],
        ];
      } else {
        $missing[] = ['hash' => $hash, 'clean' => $clean, 'raw' => $g['address'], 'g' => $g];
      }
    }

    /** 4) Géocode seulement les manquants (max 10) */
    foreach ($missing as $m) {
      if ($geocodedNow >= $maxGeocodes) {
        $pending++;
        continue;
      }

      $geo = $this->geocodeCached($m['clean']);
      if (!$geo) {
        $pending++;
        continue;
      }

      $geocodedNow++;

      $this->saveGeoCache($entite, $m['hash'], $m['raw'], $geo['lat'], $geo['lng']);

      $g = $m['g'];
      $places[] = [
        'address' => $g['address'],
        'lat' => $geo['lat'],
        'lng' => $geo['lng'],
        'cnt' => $g['cnt'],
        'amount_cents' => $g['amount_cents'],
        'sample' => $g['sample'],
      ];
    }

    // Petit tri : plus gros montant d'abord
    usort($places, fn($a, $b) => ($b['amount_cents'] <=> $a['amount_cents']));

    // DEBUG (à retirer après)
    $debug = [
      'cnt_tx' => count($tx),
      'cnt_groups' => count($groups),
      'sample_addresses' => array_slice(array_map(fn($g) => $g['address'], $groups), 0, 5),
    ];

    return $this->json([
      'places' => $places,
      'debug' => $debug,
      'meta' => [
        'cnt_places' => \count($places),
        'cnt_tx' => \count($tx),
        'filters' => $f,
        'geocoded_now' => $geocodedNow,
        'pending_groups' => $pending,
      ],
    ]);
  }



  private function addrHash(Entite $entite, string $address): string
  {
    return hash('sha256', $this->normalizeAddress($address));
  }

  /**
   * @return array<string, array{lat:float,lng:float}>
   */
  private function fetchGeoCache(Entite $entite, array $hashes): array
  {
    if (!$hashes) return [];

    $params = ['entite' => $entite->getId()];
    $in = [];
    foreach (array_values($hashes) as $i => $h) {
      $k = 'h' . $i;
      $in[] = ':' . $k;
      $params[$k] = $h;
    }

    $sql = "SELECT addr_hash, lat, lng
          FROM geo_address_cache
          WHERE entite_id = :entite
            AND addr_hash IN (" . implode(',', $in) . ")";

    $rows = $this->db->fetchAllAssociative($sql, $params);

    $out = [];
    foreach ($rows as $r) {
      if ($r['lat'] === null || $r['lng'] === null) continue;
      $out[(string)$r['addr_hash']] = ['lat' => (float)$r['lat'], 'lng' => (float)$r['lng']];
    }
    return $out;
  }

  private function saveGeoCache(
    Entite $entite,
    string $hash,
    string $address,
    float $lat,
    float $lng
  ): void {
    $this->db->executeStatement(
      "INSERT INTO geo_address_cache (entite_id, addr_hash, address, lat, lng, geocoded_at, provider)
     VALUES (:entite, :hash, :address, :lat, :lng, NOW(), 'nominatim')
     ON DUPLICATE KEY UPDATE
        address = VALUES(address),
        lat = VALUES(lat),
        lng = VALUES(lng),
        geocoded_at = NOW(),
        provider = 'nominatim'",
      [
        'entite' => $entite->getId(),
        'hash' => $hash,
        'address' => $address,
        'lat' => $lat,
        'lng' => $lng,
      ]
    );
  }

    // ============================================================
    // DATA FETCH (DBAL) — union ALX / TOTAL / EDENRED
    // ============================================================
  /**
   * @return array<int, array{
   *   provider:string,
   *   dt:?string,
   *   employe:?string,
   *   engin:?string,
   *   label:?string,
   *   categorie:?string,
   *   sousCategorie:?string,
   *   qty:?float,
   *   amount_cents:int,
   *   address:?string
   * }>
   */
  private function fetchTxForMap(Entite $entite, array $f): array
  {
    // ⚠️ Notes :
    // - On applique date + provider + engin + employé.
    // - Catégorie / sous-catégorie : ici on les laisse "NULL" si tu n’as pas un mapping direct en SQL.
    //   Si tu as déjà la logique dans ton FuelDashboardRepository, le mieux est de réutiliser la même source.
    //
    // - TOTAL a adresse + cp + ville + pays => top.
    // - EDENRED : ton extrait ne montre pas "adresse". On essaye siteLibelle / siteLibelleCourt / enseigne.
    //   Ajuste si tu as un vrai champ adresse.
    // - ALX : adresse fixe (selon ta contrainte).

    $params = [
      'entite' => $entite->getId(),
      'ds' => $f['dateStart'],
      'de' => $f['dateEnd'],
    ];

    // Providers
    $providers = $f['providers'];
    $providersNone = $f['providersNone'];

    // Engin / Employe
    $enginIds = $f['enginIds'];
    $enginNone = $f['enginNone'];
    $enginUncat = (int)$f['enginUncategorized'];

    $employeIds = $f['employeIds'];
    $employeNone = $f['employeNone'];
    $employeUncat = (int)$f['employeUncategorized'];

    $categorieNone = (bool)($f['categorieNone'] ?? false);
    $sousCategorieNone = (bool)($f['sousCategorieNone'] ?? false);

    $categorieProduits = $f['categorieProduits'] ?? [];
    $sousCategorieProduits = $f['sousCategorieProduits'] ?? [];

    // Helper pour IN()
    $inList = static function (array $ids, string $prefix) use (&$params): string {
      $ph = [];
      foreach (array_values($ids) as $i => $v) {
        $k = $prefix . $i;
        $ph[] = ':' . $k;
        $params[$k] = $v;
      }
      return $ph ? implode(',', $ph) : "''";
    };

    // Base where
    $wDate = "x.entite_id = :entite AND x.dt BETWEEN :ds AND :de";

    // Provider where
    $wProv = '';
    if ($providersNone) {
      $wProv = ' AND 1=0 ';
    } elseif (!empty($providers)) {
      $wProv = " AND x.provider IN (" . $inList($providers, 'prov') . ") ";
    }

    // Engin where (ids + __NULL__)
    $wEngin = '';
    if ($enginNone) {
      $wEngin = ' AND 1=0 ';
    } elseif (!empty($enginIds) || $enginUncat) {
      $parts = [];
      if (!empty($enginIds)) {
        $parts[] = "x.engin_id IN (" . $inList($enginIds, 'eng') . ")";
      }
      if ($enginUncat) {
        $parts[] = "x.engin_id IS NULL";
      }
      $wEngin = ' AND (' . implode(' OR ', $parts) . ') ';
    }

    // Employe where
    $wEmp = '';
    if ($employeNone) {
      $wEmp = ' AND 1=0 ';
    } elseif (!empty($employeIds) || $employeUncat) {
      $parts = [];
      if (!empty($employeIds)) {
        $parts[] = "x.utilisateur_id IN (" . $inList($employeIds, 'emp') . ")";
      }
      if ($employeUncat) {
        $parts[] = "x.utilisateur_id IS NULL";
      }
      $wEmp = ' AND (' . implode(' OR ', $parts) . ') ';
    }

    // Catégorie
    $wCat = '';
    if ($categorieNone) {
      $wCat = ' AND 1=0 ';
    } elseif (!empty($categorieProduits)) {
      $wCat = " AND x.categorie IN (" . $inList($categorieProduits, 'cat') . ") ";
    }

    // Sous-catégorie
    $wSous = '';
    if ($sousCategorieNone) {
      $wSous = ' AND 1=0 ';
    } elseif (!empty($sousCategorieProduits)) {
      $wSous = " AND x.sous_categorie IN (" . $inList($sousCategorieProduits, 'sous') . ") ";
    }

    // ⚠️ Ici on ne gère pas categorie/sousCategorie en SQL (tu peux les rajouter si tu as une table de mapping).
    // Mais on renvoie les champs pour la popup.
    $sql = "
  SELECT
    x.provider,
    x.dt,
    x.employe_label  AS employe,
    x.engin_label    AS engin,
    x.label,
    x.categorie,
    x.sous_categorie AS sousCategorie,
    x.qty,
    x.amount_cents,
    x.address
  FROM (
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

    /* ✅ IMPORTANT : union */
    UNION ALL

    /* ---------- EDENRED ---------- */
    SELECT
      'EDENRED' AS provider,
      ed.entite_id,
      ed.date_transaction AS dt,

      /* ids : relation directe OU mapping external_id */
      COALESCE(ed.engin_id, e_ext2.id, e_ext_code.id) AS engin_id,
      COALESCE(ed.utilisateur_id, u_ext_code.id) AS utilisateur_id,

      NULLIF(TRIM(
        COALESCE(
          CONCAT(COALESCE(u3.prenom,''),' ',COALESCE(u3.nom,'')),
          CONCAT(COALESCE(u_ext_code.prenom,''),' ',COALESCE(u_ext_code.nom,'')),
          ed.code_chauffeur
        )
      ), '') AS employe,

      NULLIF(TRIM(
        COALESCE(
          e2.nom,
          e_ext2.nom,
          e_ext_code.nom,
          ed.immatriculation,
          ed.code_vehicule
        )
      ), '') AS engin,

      COALESCE(ed.produit, ed.type_transaction) AS label,

      CASE
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'gnr|gasoil|diesel|essence|ethanol|e85|adblue'
          THEN 'carburant'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'péage|peage|parking'
          THEN 'route'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'huile|lubr|lave|lavage|entretien'
          THEN 'entretien'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'accessoire'
          THEN 'accessoire'
        ELSE 'divers'
      END AS categorie,

      CASE
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'gnr' THEN 'gnr'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'adblue' THEN 'adblue'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'ethanol|e85' THEN 'ethanol'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'essence' THEN 'essence'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'gasoil|diesel' THEN 'gasoil'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'péage|peage' THEN 'peage'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'parking' THEN 'parking'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'huile|lubr' THEN 'lubrifiant'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'lavage|lave' THEN 'lavage'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'boutique' THEN 'boutique'
        WHEN LOWER(COALESCE(ed.produit, ed.type_transaction, '')) REGEXP 'accessoire' THEN 'accessoire'
        ELSE NULL
      END AS sous_categorie,

      CAST(ed.quantite AS DECIMAL(12,3)) AS qty,
      CAST(ROUND(COALESCE(ed.montant_ttc,0)*100,0) AS SIGNED) AS amount_cents,

      TRIM(CONCAT(
        COALESCE(ed.site_libelle,''),
        CASE WHEN ed.site_libelle_court IS NULL OR ed.site_libelle_court='' THEN '' ELSE CONCAT(', ', ed.site_libelle_court) END,
        CASE WHEN ed.client_nom IS NULL OR ed.client_nom='' THEN '' ELSE CONCAT(', ', ed.client_nom) END,
        ', France'
      )) AS address

    FROM transaction_carte_edenred ed
    LEFT JOIN engin e2 ON e2.id = ed.engin_id
    LEFT JOIN utilisateur u3 ON u3.id = ed.utilisateur_id

    /* mapping immatriculation (déjà chez toi) */
    LEFT JOIN engin_external_id ee2
      ON ee2.provider = 'edenred'
    AND ee2.active = 1
    AND ee2.value = REPLACE(REPLACE(UPPER(TRIM(COALESCE(ed.immatriculation,''))), ' ', ''), '-', '')
    LEFT JOIN engin e_ext2
      ON e_ext2.id = ee2.engin_id
    AND e_ext2.entite_id = ed.entite_id

    /* mapping engin via code_vehicule */
    LEFT JOIN engin_external_id ee_code
      ON ee_code.provider = 'edenred'
    AND ee_code.active = 1
    AND ee_code.value = REPLACE(REPLACE(UPPER(TRIM(COALESCE(ed.code_vehicule,''))), ' ', ''), '-', '')
    LEFT JOIN engin e_ext_code
      ON e_ext_code.id = ee_code.engin_id
    AND e_ext_code.entite_id = ed.entite_id

    /* mapping employé via code_chauffeur */
    LEFT JOIN utilisateur_external_id ue_code
      ON ue_code.provider = 'edenred'
    AND ue_code.active = 1
    AND ue_code.value = REPLACE(REPLACE(UPPER(TRIM(COALESCE(ed.code_chauffeur,''))), ' ', ''), '-', '')
    LEFT JOIN utilisateur u_ext_code
      ON u_ext_code.id = ue_code.utilisateur_id

    WHERE ed.entite_id = :entite
      AND ed.date_transaction BETWEEN :ds AND :de

    /* ✅ IMPORTANT : union */
    UNION ALL

    /* ---------- ALX ---------- */
    SELECT
      'ALX' AS provider,
      a.entite_id,
      CASE
        WHEN a.journee IS NULL THEN NULL
        WHEN a.horaire IS NULL THEN a.journee
        ELSE CONCAT(DATE_FORMAT(a.journee, '%Y-%m-%d'), ' ', DATE_FORMAT(a.horaire, '%H:%i:%s'))
      END AS dt,

      COALESCE(a.engin_id, e_ext_alx.id) AS engin_id,
      COALESCE(a.utilisateur_id, u_ext_alx.id) AS utilisateur_id,

      NULLIF(TRIM(
        COALESCE(
          a.agent,
          CONCAT(COALESCE(u2.prenom,''),' ',COALESCE(u2.nom,'')),
          CONCAT(COALESCE(u_ext_alx.prenom,''),' ',COALESCE(u_ext_alx.nom,'')),
          a.code_agent
        )
      ), '') AS employe,

      NULLIF(TRIM(
        COALESCE(
          e3.nom,
          e_ext_alx.nom,
          a.vehicule,
          a.code_veh
        )
      ), '') AS engin,

      'Carburant' AS label,
      'carburant' AS categorie,
      NULL AS sous_categorie,
      CAST(a.quantite AS DECIMAL(12,3)) AS qty,
      0 AS amount_cents,
      :alxAddress AS address

    FROM transaction_carte_alx a
    LEFT JOIN engin e3 ON e3.id = a.engin_id
    LEFT JOIN utilisateur u2 ON u2.id = a.utilisateur_id

    /* mapping engin via code_veh */
    LEFT JOIN engin_external_id ee_alx
      ON ee_alx.provider = 'alx'
    AND ee_alx.active = 1
    AND ee_alx.value = REPLACE(REPLACE(UPPER(TRIM(COALESCE(a.code_veh,''))), ' ', ''), '-', '')
    LEFT JOIN engin e_ext_alx
      ON e_ext_alx.id = ee_alx.engin_id
    AND e_ext_alx.entite_id = a.entite_id

    /* mapping employé via code_agent */
    LEFT JOIN utilisateur_external_id ue_alx
      ON ue_alx.provider = 'alx'
    AND ue_alx.active = 1
    AND ue_alx.value = REPLACE(REPLACE(UPPER(TRIM(COALESCE(a.code_agent,''))), ' ', ''), '-', '')
    LEFT JOIN utilisateur u_ext_alx
      ON u_ext_alx.id = ue_alx.utilisateur_id

    WHERE a.entite_id = :entite
      AND a.journee BETWEEN :ds AND :de
  ) x
  WHERE {$wDate}
    {$wProv}
    {$wEngin}
    {$wEmp}
    {$wCat}
    {$wSous}
    AND x.address IS NOT NULL
    AND TRIM(x.address) <> ''
  ORDER BY x.dt DESC
  LIMIT 2500
";

    $params['alxAddress'] = self::ALX_FIXED_ADDRESS;

    /** @var array<int,array<string,mixed>> $rows */
    $rows = $this->db->fetchAllAssociative($sql, $params);

    // normalisation sortie
    $out = [];
    foreach ($rows as $r) {
      $out[] = [
        'provider' => (string)($r['provider'] ?? ''),
        'dt' => $this->toIsoish($r['dt'] ?? null),
        'employe' => $this->cleanPerson($r['employe'] ?? null),
        'engin' => $this->cleanText($r['engin'] ?? null),
        'label' => $this->cleanText($r['label'] ?? null),
        'categorie' => $this->cleanText($r['categorie'] ?? null),
        'sousCategorie' => $this->cleanText($r['sousCategorie'] ?? null),
        'qty' => isset($r['qty']) ? (float)$r['qty'] : null,
        'amount_cents' => (int)($r['amount_cents'] ?? 0),
        'address' => $this->cleanText($r['address'] ?? null),
      ];
    }

    return $out;
  }

    // ============================================================
    // FILTERS PARSING (same as JS)
    // ============================================================
  /**
   * @return array{
   *   dateStart:string,
   *   dateEnd:string,
   *   providersNone:bool,
   *   providers:array<int,string>,
   *   enginNone:bool,
   *   enginIds:array<int,int>,
   *   enginUncategorized:bool,
   *   employeNone:bool,
   *   employeIds:array<int,int>,
   *   employeUncategorized:bool,
   *   categorieNone:bool,
   *   categorieProduits:array<int,string>,
   *   sousCategorieNone:bool,
   *   sousCategorieProduits:array<int,string>,
   * }
   */
  private function readFilters(Request $request): array
  {
    $ds = (string)$request->query->get('dateStart', '');
    $de = (string)$request->query->get('dateEnd', '');

    // harden dates
    $ds = $this->safeDate($ds) ?? (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
    $de = $this->safeDate($de) ?? (new \DateTimeImmutable('today'))->format('Y-m-d');

    $providersNone = (bool)$request->query->get('providersNone', false);
    $enginNone = (bool)$request->query->get('enginNone', false);
    $employeNone = (bool)$request->query->get('employeNone', false);
    $categorieNone = (bool)$request->query->get('categorieNone', false);
    $sousCategorieNone = (bool)$request->query->get('sousCategorieNone', false);

    $enginUncategorized = (bool)$request->query->get('enginUncategorized', false);
    $employeUncategorized = (bool)$request->query->get('employeUncategorized', false);

    $providers = $request->query->all('providers');
    $providers = array_values(array_filter(array_map('strval', is_array($providers) ? $providers : [])));
    $providers = array_values(array_unique(array_map('strtoupper', $providers)));

    $enginIds = $request->query->all('enginIds');
    $enginIds = array_values(array_filter(array_map('intval', is_array($enginIds) ? $enginIds : []), fn($v) => $v > 0));

    $employeIds = $request->query->all('employeIds');
    $employeIds = array_values(array_filter(array_map('intval', is_array($employeIds) ? $employeIds : []), fn($v) => $v > 0));

    $categorieProduits = $request->query->all('categorieProduits');
    $categorieProduits = array_values(array_filter(array_map('strval', is_array($categorieProduits) ? $categorieProduits : [])));

    $sousCategorieProduits = $request->query->all('sousCategorieProduits');
    $sousCategorieProduits = array_values(array_filter(array_map('strval', is_array($sousCategorieProduits) ? $sousCategorieProduits : [])));

    return [
      'dateStart' => $ds,
      'dateEnd' => $de,

      'providersNone' => $providersNone,
      'providers' => $providers,

      'enginNone' => $enginNone,
      'enginIds' => $enginIds,
      'enginUncategorized' => $enginUncategorized,

      'employeNone' => $employeNone,
      'employeIds' => $employeIds,
      'employeUncategorized' => $employeUncategorized,

      'categorieNone' => $categorieNone,
      'categorieProduits' => $categorieProduits,

      'sousCategorieNone' => $sousCategorieNone,
      'sousCategorieProduits' => $sousCategorieProduits,
    ];
  }

  private function safeDate(string $s): ?string
  {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    try {
      return (new \DateTimeImmutable($s))->format('Y-m-d');
    } catch (\Throwable) {
      return null;
    }
  }

    // ============================================================
    // GEOCODING (Nominatim) with cache
    // ============================================================
  /**
   * @return array{lat:float,lng:float}|null
   */
  private function geocodeCached(string $address): ?array
  {
    $address = trim($address);
    if ($address === '') return null;

    // ✅ Circuit breaker global : si Nominatim a répondu 429 récemment, on stoppe tout
    $blocked = $this->cache->get('fuel_nominatim_blocked', function (CacheItem $i) {
      $i->expiresAfter(1);
      return false;
    });
    if ($blocked) {
      return null;
    }

    $key = 'fuel_geocode_' . sha1($this->normalizeAddress($address));

    return $this->cache->get($key, function (CacheItem $item) use ($address) {
      // valeur par défaut : long cache
      $item->expiresAfter(3600 * 24 * 180);

      try {
        [$geo, $status] = $this->geocodeNominatimWithStatus($address);

        if ($geo !== null) {
          return $geo;
        }

        // ✅ rate limit / temporaire => cache très court
        if ($status === 429 || $status === 403) {
          $this->cache->get('fuel_nominatim_blocked', function (CacheItem $i) {
            $i->expiresAfter(600);
            return true;
          });
        }

        // échec "normal" => cache moyen
        $item->expiresAfter(3600 * 6);
        return null;
      } catch (\Throwable) {
        // timeout / réseau => cache très court
        $item->expiresAfter(120);
        return null;
      }
    });
  }

  /**
   * @return array{0:?array{lat:float,lng:float},1:int}
   */
  private function geocodeNominatimWithStatus(string $address): array
  {
    $address = $this->cleanupForGeocode($address);
    if ($address === '') return [null, 0];

    $resp = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
      'query' => [
        'q' => $address,
        'format' => 'jsonv2',
        'limit' => 1,
        'addressdetails' => 0,
      ],
      'headers' => [
        'User-Agent' => 'PhilipFreresFuel/1.0 (contact: contact@jeroensnow.fr)',
        'Referer' => 'https://jeroensnow.fr',
        'Accept' => 'application/json',
      ],
      'timeout' => 10,
    ]);

    $status = $resp->getStatusCode();

    // ✅ si rate-limited : on active le "circuit breaker" pour 10 minutes
    if ($status === 429 || $status === 403) {
      $this->cache->delete('fuel_nominatim_blocked'); // au cas où
      $this->cache->get('fuel_nominatim_blocked', function (CacheItem $i) {
        $i->expiresAfter(600); // 10 minutes
        return true;
      });
      return [null, $status];
    }

    if ($status !== 200) return [null, $status];

    $data = $resp->toArray(false);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
      return [null, 200];
    }

    $lat = (float) $data[0]['lat'];
    $lng = (float) $data[0]['lon'];
    if (!is_finite($lat) || !is_finite($lng)) return [null, 200];

    return [['lat' => $lat, 'lng' => $lng], 200];
  }

  /**
   * @return array{lat:float,lng:float}|null
   */
  private function geocodeNominatim(string $address): ?array
  {
    $address = $this->cleanupForGeocode($address);
    if ($address === '') return null;

    $url = 'https://nominatim.openstreetmap.org/search';

    try {
      $resp = $this->httpClient->request('GET', $url, [
        'query' => [
          'q' => $address,
          'format' => 'jsonv2',
          'limit' => 1,
          'addressdetails' => 0,
        ],
        'headers' => [
          'User-Agent' => 'PhilipFreresFuel/1.0 (https://jeroensnow.fr; contact@jeroensnow.fr)',
          'Referer' => 'https://jeroensnow.fr',
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
      ]);

      if (200 !== $resp->getStatusCode()) {
        return null;
      }

      $data = $resp->toArray(false);
      if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
        return null;
      }

      $lat = (float) $data[0]['lat'];
      $lng = (float) $data[0]['lon'];
      if (!is_finite($lat) || !is_finite($lng)) return null;

      return ['lat' => $lat, 'lng' => $lng];
    } catch (\Throwable) {
      return null;
    }
  }

  private function normalizeAddress(string $address): string
  {
    $a = mb_strtolower(trim($address));
    $a = preg_replace('/\s+/', ' ', $a) ?? $a;
    $a = str_replace(['.', ';', '|'], ',', $a);
    $a = preg_replace('/\s*,\s*/', ', ', $a) ?? $a;
    return $a;
  }

  // ============================================================
  // Small cleaners
  // ============================================================
  private function cleanText(mixed $v): ?string
  {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
  }

  private function cleanPerson(mixed $v): ?string
  {
    $s = $this->cleanText($v);
    if ($s === null) return null;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s) ?: null;
  }

  private function toIsoish(mixed $v): ?string
  {
    if ($v === null) return null;

    // Déjà une string "YYYY-mm-dd hh:ii:ss" ou "YYYY-mm-dd"
    if (is_string($v)) {
      $s = trim($v);
      if ($s === '') return null;
      // si format "YYYY-mm-dd" => on laisse
      return $s;
    }

    if ($v instanceof \DateTimeInterface) {
      return $v->format('Y-m-d H:i:s');
    }

    return null;
  }


  #[Route('/api/geocode-test', name: 'api_geocode_test', methods: ['GET'])]
  public function apiGeocodeTest(Entite $entite, Request $request): JsonResponse
  {
    $address = (string) $request->query->get('q', '');
    $clean = $this->cleanupForGeocode($address);

    $url = 'https://nominatim.openstreetmap.org/search';

    $blocked = $this->cache->get('fuel_nominatim_blocked', function (CacheItem $i) {
      $i->expiresAfter(1);
      return false;
    });

    if ($blocked) {
      return $this->json([
        'blocked' => true,
        'message' => 'Nominatim est temporairement bloqué (cooldown).',
      ], 429);
    }

    try {
      $resp = $this->httpClient->request('GET', $url, [
        'query' => [
          'q' => $clean,
          'format' => 'jsonv2',
          'limit' => 1,
          'addressdetails' => 0,
        ],
        'headers' => [
          // ⚠️ mets un vrai contact
          'User-Agent' => 'PhilipFreresFuel/1.0 (contact: contact@ton-domaine.fr)',
          'Accept' => 'application/json',
        ],
        'timeout' => 15,
      ]);

      $status = $resp->getStatusCode();
      $body = $resp->getContent(false); // même si 429/403

      return $this->json([
        'input' => $address,
        'clean' => $clean,
        'status' => $status,
        'body_preview' => mb_substr($body, 0, 800),
      ]);
    } catch (\Throwable $e) {
      return $this->json([
        'input' => $address,
        'clean' => $clean,
        'error' => $e->getMessage(),
      ], 500);
    }
  }


  private function cleanupForGeocode(string $address): string
  {
    $a = trim($address);
    if ($a === '') return '';

    // normalise espaces
    $a = preg_replace('/\s+/', ' ', $a) ?? $a;

    // split par virgules, trim, enlève vides
    $parts = array_values(array_filter(array_map('trim', explode(',', $a)), fn($p) => $p !== ''));

    // enlève doublons exacts (case-insensitive)
    $seen = [];
    $clean = [];
    foreach ($parts as $p) {
      $k = mb_strtolower($p, 'UTF-8');
      if (isset($seen[$k])) continue;
      $seen[$k] = true;
      $clean[] = $p;
    }

    // supprime bruit “client / société”
    $stop = [
      'philip freres',
      'philip frères',
      'france', // on le remet à la fin si besoin
    ];
    $clean = array_values(array_filter($clean, function ($p) use ($stop) {
      $k = mb_strtolower($p, 'UTF-8');
      foreach ($stop as $s) {
        if (str_contains($k, $s)) return false;
      }
      return true;
    }));

    // reconstruit une base
    $base = trim(implode(', ', $clean));

    // fix marques fréquentes (SUPERUS -> Super U, etc.)
    $base = preg_replace('/\bSUPERUS\b/i', 'Super U', $base) ?? $base;
    $base = preg_replace('/\bINTERMARCHE\b/i', 'Intermarché', $base) ?? $base;
    $base = preg_replace('/\bAVIA\b/i', 'AVIA', $base) ?? $base;

    // enlève ponctuation “agressive”
    $base = str_replace([';', '|'], ' ', $base);
    $base = preg_replace('/\s+/', ' ', $base) ?? $base;
    $base = trim($base);

    // si pas déjà de pays, ajoute France
    $low = mb_strtolower($base, 'UTF-8');
    if ($base !== '' && !str_contains($low, 'france')) {
      $base .= ', France';
    }

    return $base;
  }
}
