<?php

namespace App\Repository;

use App\Entity\{Chantier, Utilisateur};
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
use App\Enum\ChantierStatut;

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


    public function createVisibleListQb(
        Entite $entite,
        Utilisateur $user,
        bool $isTenantAdmin,
        string $search = ''
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.utilisateursAffectes', 'ua')
            ->addSelect('ua')
            ->andWhere('c.entite = :entite')
            ->setParameter('entite', $entite);

        if (!$isTenantAdmin) {
            $qb
                ->andWhere('ua = :user')
                ->andWhere('c.statut != :brouillon')
                ->setParameter('user', $user)
                ->setParameter('brouillon', ChantierStatut::BROUILLON);
        }

        if ($search !== '') {
            $qb
                ->andWhere('LOWER(c.nom) LIKE :search OR LOWER(c.ville) LIKE :search OR LOWER(c.adresse) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        return $qb;
    }

    public function countVisibleForUser(
        Entite $entite,
        Utilisateur $user,
        bool $isTenantAdmin
    ): int {
        $qb = $this->createVisibleListQb($entite, $user, $isTenantAdmin)
            ->select('COUNT(DISTINCT c.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
