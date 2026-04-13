<?php

namespace App\Repository;

use App\Entity\Entite;
use App\Entity\Materiel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MaterielRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Materiel::class);
    }

    public function findForEntite(Entite $entite): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.entite = :entite')
            ->setParameter('entite', $entite)
            ->orderBy('m.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
