<?php
// src/Repository/GeoAddressCacheRepository.php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GeoAddressCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;

final class GeoAddressCacheRepository extends ServiceEntityRepository
{
  public function __construct(
    ManagerRegistry $registry,
    private readonly Connection $db,
  ) {
    parent::__construct($registry, GeoAddressCache::class);
  }

  /**
   * @return array<int, array{
   *   id:int,
   *   entite_id:int,
   *   addr_hash:string,
   *   address:string,
   *   lat:float|null,
   *   lng:float|null
   * }>
   */
  public function findToGeocode(int $entiteId, int $limit = 200): array
  {
    $sql = "
          SELECT id, entite_id, addr_hash, address, lat, lng
          FROM geo_address_cache
          WHERE entite_id = :entite
            AND (lat IS NULL OR lng IS NULL)
          ORDER BY id ASC
          LIMIT :lim
        ";

    return $this->db->fetchAllAssociative($sql, [
      'entite' => $entiteId,
      'lim' => $limit,
    ], [
      'lim' => \PDO::PARAM_INT,
    ]);
  }

  public function markGeocoded(
    int $id,
    ?float $lat,
    ?float $lng,
    ?string $provider = 'nominatim',
    ?string $displayName = null,
    ?int $confidence = null,
  ): void {
    $this->db->executeStatement("
          UPDATE geo_address_cache
          SET lat = :lat,
              lng = :lng,
              provider = :provider,
              display_name = :display,
              confidence = :conf,
              geocoded_at = :at
          WHERE id = :id
        ", [
      'id' => $id,
      'lat' => $lat,
      'lng' => $lng,
      'provider' => $provider,
      'display' => $displayName,
      'conf' => $confidence,
      'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
  }
}
