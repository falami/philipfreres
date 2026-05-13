<?php

namespace App\Repository;

use App\Entity\{EnginUsageReleve, Entite};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class EnginUsageReleveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnginUsageReleve::class);
    }

    public function dashboardSummary(Entite $entite, array $f): array
    {
        $rows = $this->baseQb($entite, $f)
            ->select("
            SUBSTRING(r.dateReleve, 1, 7) AS month,
            e.compteurType AS compteurType,
            SUM(r.valeur) AS totalUsage,
            AVG(r.valeur) AS avgUsage,
            COUNT(r.id) AS nb
        ")
            ->groupBy('month', 'e.compteurType')
            ->orderBy('month', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $totalUsage = 0.0;
        $nb = 0;
        $monthly = [];

        foreach ($rows as $r) {
            $monthly[] = [
                'month' => $r['month'],
                'type' => $r['compteurType'],
                'usage' => (float) $r['totalUsage'],
                'avg' => (float) $r['avgUsage'],
                'nb' => (int) $r['nb'],
            ];

            $totalUsage += (float) $r['totalUsage'];
            $nb += (int) $r['nb'];
        }

        return [
            'kpis' => [
                'totalUsage' => round($totalUsage, 2),
                'avgUsage' => $nb > 0 ? round($totalUsage / $nb, 2) : 0,
                'nbReleves' => $nb,
            ],
            'charts' => [
                'monthly' => $monthly,
            ],
        ];
    }

    public function fetchDtRows(Entite $entite, array $f, int $start, int $length, string $search = ''): array
    {
        $qb = $this->baseQb($entite, $f)
            ->select('r.id, r.dateReleve, r.valeur, e.nom AS enginNom, e.compteurType AS compteurType');

        if ($search !== '') {
            $qb->andWhere('e.nom LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        $countQb = clone $qb;
        $countQb->resetDQLPart('select')->resetDQLPart('orderBy');

        $filtered = (int) $countQb
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $total = (int) $this->createQueryBuilder('rt')
            ->select('COUNT(rt.id)')
            ->andWhere('rt.entite = :entite')
            ->setParameter('entite', $entite)
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $qb
            ->orderBy('r.dateReleve', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()
            ->getArrayResult();

        return [$rows, $total, $filtered];
    }


    private function baseQb(Entite $entite, array $f)
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.engin', 'e')
            ->andWhere('r.entite = :entite')
            ->andWhere('r.dateReleve BETWEEN :start AND :end')
            ->setParameter('entite', $entite)
            ->setParameter('start', new \DateTimeImmutable($f['dateStart']))
            ->setParameter('end', new \DateTimeImmutable($f['dateEnd']));

        if (!empty($f['enginId'])) {
            $qb->andWhere('e.id = :enginId')
                ->setParameter('enginId', (int) $f['enginId']);
        }

        return $qb;
    }
}
