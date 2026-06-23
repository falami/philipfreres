<?php

namespace App\Repository;

use App\Entity\Entite;
use App\Entity\Mandataire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MandataireRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, Mandataire::class);
  }

  public function createListQb(Entite $entite, ?string $search = null)
  {
    $qb = $this->createQueryBuilder('m')
      ->andWhere('m.entite = :entite')
      ->setParameter('entite', $entite);

    if ($search) {
      $qb->andWhere('m.nom LIKE :q OR m.societe LIKE :q OR m.email LIKE :q OR m.ville LIKE :q')
        ->setParameter('q', '%' . $search . '%');
    }

    return $qb;
  }
}
