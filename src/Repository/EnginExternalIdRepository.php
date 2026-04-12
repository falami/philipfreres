<?php
// src/Repository/EnginExternalIdRepository.php

namespace App\Repository;

use App\Entity\{Engin, EnginExternalId, Entite};
use App\Enum\ExternalProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class EnginExternalIdRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, EnginExternalId::class);
  }

  public function findActiveEnginByProviderValue(Entite $entite, ExternalProvider $provider, string $value): ?Engin
  {
    $x = $this->createQueryBuilder('x')
      ->select('x')
      ->addSelect('e')
      ->innerJoin('x.engin', 'e')
      ->andWhere('e.entite = :entite')
      ->andWhere('x.provider = :provider')
      ->andWhere('x.active = true')
      ->andWhere('x.value = :v')
      ->setParameter('entite', $entite)
      ->setParameter('provider', $provider)
      ->setParameter('v', $value)
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();

    return $x?->getEngin();
  }

  public function countAllForEntite(Entite $entite): int
  {
    return (int) $this->createQueryBuilder('x')
      ->select('COUNT(x.id)')
      ->join('x.engin', 'e')
      ->andWhere('e.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()
      ->getSingleScalarResult();
  }

  /**
   * @return array{rows: array<int,array{x:EnginExternalId,e:object}>, filtered:int}
   */
  public function fetchAllForEntiteDataTable(
    Entite $entite,
    int $start,
    int $length,
    string $orderBy,
    string $orderDir,
    string $providerFilter,
    string $activeFilter,
    string $searchAny,
  ): array {
    $qb = $this->createQueryBuilder('x')
      ->addSelect('e')
      ->join('x.engin', 'e')
      ->andWhere('e.entite = :entite')
      ->setParameter('entite', $entite);

    // Provider
    if ($providerFilter !== 'all' && $providerFilter !== '') {
      // providerFilter = valeur enum (ex: 'total', 'alx', 'edenred')
      $qb->andWhere('x.provider = :prov')
        ->setParameter('prov', ExternalProvider::from($providerFilter));
    }

    // Active
    if ($activeFilter === '1' || $activeFilter === '0') {
      $qb->andWhere('x.active = :act')
        ->setParameter('act', $activeFilter === '1');
    }

    // Search global
    if ($searchAny !== '') {
      $qb->andWhere('(
                LOWER(x.value) LIKE :q
                OR LOWER(COALESCE(x.note, \'\')) LIKE :q
                OR LOWER(COALESCE(e.nom, \'\')) LIKE :q
            )')
        ->setParameter('q', '%' . mb_strtolower($searchAny) . '%');
    }

    // recordsFiltered
    $qbCount = clone $qb;
    $filtered = (int) $qbCount
      ->select('COUNT(x.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()
      ->getSingleScalarResult();

    // order
    // (sécurité : on autorise seulement certains champs)
    $allowed = ['x.id', 'e.nom', 'x.provider', 'x.value', 'x.active', 'x.createdAt', 'x.disabledAt', 'x.note'];
    if (!in_array($orderBy, $allowed, true)) {
      $orderBy = 'x.id';
    }
    $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

    $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length);

    // Résultats : on renvoie "x" et "e" pour formatage contrôleur
    $rows = [];
    foreach ($qb->getQuery()->getResult() as $x) {
      $rows[] = ['x' => $x, 'e' => $x->getEngin()];
    }

    return ['rows' => $rows, 'filtered' => $filtered];
  }
}
