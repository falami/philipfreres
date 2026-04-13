<?php

namespace App\Repository;

use App\Entity\DechetType;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DechetTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DechetType::class);
    }

    public function findForEntite(Entite $entite): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.entite = :entite')
            ->setParameter('entite', $entite)
            ->orderBy('d.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
