<?php

namespace App\Repository;

use App\Entity\Chantier;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

class ChantierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chantier::class);
    }

    public function createListQb(Entite $entite, string $search = ''): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.entite = :entite')
            ->setParameter('entite', $entite);

        if ($search !== '') {
            $qb
                ->andWhere('(c.nom LIKE :q OR c.ville LIKE :q OR c.adresse LIKE :q)')
                ->setParameter('q', '%' . $search . '%');
        }

        return $qb;
    }

    public function countForEntite(Entite $entite): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.entite = :entite')
            ->setParameter('entite', $entite)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
