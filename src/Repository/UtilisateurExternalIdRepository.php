<?php
// src/Repository/UtilisateurExternalIdRepository.php

namespace App\Repository;

use App\Entity\{Entite, UtilisateurExternalId};
use App\Enum\ExternalProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class UtilisateurExternalIdRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, UtilisateurExternalId::class);
  }

  public function countAllForEntite(Entite $entite): int
  {
    return (int) $this->createQueryBuilder('x')
      ->select('COUNT(x.id)')
      ->join('x.utilisateur', 'u')
      ->andWhere('u.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()
      ->getSingleScalarResult();
  }

  /**
   * @return array{rows: array<int,array{x:UtilisateurExternalId,u:object}>, filtered:int}
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
      ->addSelect('u')
      ->join('x.utilisateur', 'u')
      ->andWhere('u.entite = :entite')
      ->setParameter('entite', $entite);

    // Provider
    if ($providerFilter !== 'all' && $providerFilter !== '') {
      $qb->andWhere('x.provider = :prov')
        ->setParameter('prov', ExternalProvider::from($providerFilter));
    }

    // Active
    if ($activeFilter === '1' || $activeFilter === '0') {
      $qb->andWhere('x.active = :act')
        ->setParameter('act', $activeFilter === '1');
    }

    // Search global (fusion custom + datatables)
    if ($searchAny !== '') {
      $q = '%' . mb_strtolower($searchAny) . '%';
      $qb->andWhere('(
        LOWER(x.value) LIKE :q
        OR LOWER(COALESCE(x.note, \'\')) LIKE :q
        OR LOWER(COALESCE(u.nom, \'\')) LIKE :q
        OR LOWER(COALESCE(u.prenom, \'\')) LIKE :q
        OR LOWER(COALESCE(u.email, \'\')) LIKE :q
      )')
        ->setParameter('q', $q);
    }

    // recordsFiltered
    $qbCount = clone $qb;
    $filtered = (int) $qbCount
      ->select('COUNT(x.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()
      ->getSingleScalarResult();

    // order (sécurité)
    $allowed = ['x.id', 'u.nom', 'u.prenom', 'u.email', 'x.provider', 'x.value', 'x.active', 'x.createdAt', 'x.disabledAt', 'x.note'];
    if (!in_array($orderBy, $allowed, true)) $orderBy = 'x.id';
    $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

    $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length);

    $rows = [];
    foreach ($qb->getQuery()->getResult() as $x) {
      $rows[] = ['x' => $x, 'u' => $x->getUtilisateur()];
    }

    return ['rows' => $rows, 'filtered' => $filtered];
  }
}
