<?php

namespace App\Repository;

use App\Entity\{
    EnginUsageReleve,
    Entite,
    TransactionCarteAlx,
    TransactionCarteTotal,
    TransactionCarteEdenred,
    Note
};
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
            ->select('
            r.id,
            r.dateReleve,
            r.valeur,
            e.id AS enginId,
            e.nom AS enginNom,
            e.compteurType AS compteurType
        ');

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

        foreach ($rows as &$row) {
            $enginId = (int) $row['enginId'];

            $last = $this->findLastReleveForEngin($entite, $enginId);
            $previous = $this->findPreviousReleveForEngin(
                $entite,
                $enginId,
                $row['dateReleve'],
                (int) $row['id']
            );

            $lastDate = $last['dateReleve'] ?? null;

            $row['lastDateReleve'] = $lastDate;
            $row['daysSinceLast'] = $lastDate instanceof \DateTimeInterface
                ? $lastDate->diff(new \DateTimeImmutable())->days
                : null;

            $row['previousValeur'] = $previous['valeur'] ?? null;
            $row['previousDateReleve'] = $previous['dateReleve'] ?? null;
            $row['ecartValeur'] = $previous
                ? (float) $row['valeur'] - (float) $previous['valeur']
                : null;

            $row['fuelLitres'] = null;
            $row['consommation'] = null;

            if (
                $previous
                && $row['previousDateReleve'] instanceof \DateTimeInterface
                && $row['dateReleve'] instanceof \DateTimeInterface
                && $row['ecartValeur'] !== null
                && (float) $row['ecartValeur'] > 0
            ) {
                $litres = $this->sumFuelLitresBetween(
                    $entite,
                    $enginId,
                    $row['previousDateReleve'],
                    $row['dateReleve']
                );

                $delta = (float) $row['ecartValeur'];
                $type = $this->normalizeCompteurType($row['compteurType'] ?? null);

                $row['fuelLitres'] = $litres;

                if ($litres > 0) {
                    $row['consommation'] = $type === 'kilometre'
                        ? ($litres / $delta) * 100
                        : $litres / $delta;
                }
            }

            $row['isLate'] = false;

            if ($lastDate instanceof \DateTimeInterface) {
                $today = new \DateTimeImmutable('today');
                $lastDay = \DateTimeImmutable::createFromInterface($lastDate)->setTime(0, 0);

                $daysSinceRealLast = $lastDay->diff($today)->days;

                $row['isLate'] = $daysSinceRealLast > 8;
            }
        }

        return [$rows, $total, $filtered];
    }

    private function findLastReleveForEngin(Entite $entite, int $enginId): ?array
    {
        return $this->createQueryBuilder('r')
            ->select('r.dateReleve, r.valeur')
            ->innerJoin('r.engin', 'e')
            ->andWhere('r.entite = :entite')
            ->andWhere('e.id = :enginId')
            ->setParameter('entite', $entite)
            ->setParameter('enginId', $enginId)
            ->orderBy('r.dateReleve', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function findPreviousReleveForEngin(
        Entite $entite,
        int $enginId,
        \DateTimeInterface $dateReleve,
        int $currentId
    ): ?array {
        return $this->createQueryBuilder('r')
            ->select('r.dateReleve, r.valeur')
            ->innerJoin('r.engin', 'e')
            ->andWhere('r.entite = :entite')
            ->andWhere('e.id = :enginId')
            ->andWhere('(r.dateReleve < :dateReleve OR (r.dateReleve = :dateReleve AND r.id < :currentId))')
            ->setParameter('entite', $entite)
            ->setParameter('enginId', $enginId)
            ->setParameter('dateReleve', $dateReleve)
            ->setParameter('currentId', $currentId)
            ->orderBy('r.dateReleve', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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


    public function fetchDashboardReleves(Entite $entite, array $f): array
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.engin', 'e')
            ->select('
            r.id,
            r.dateReleve,
            r.valeur,
            e.id AS enginId,
            e.nom AS enginNom,
            e.compteurType AS compteurType
        ')
            ->andWhere('r.entite = :entite')
            ->andWhere('r.dateReleve BETWEEN :start AND :end')
            ->setParameter('entite', $entite)
            ->setParameter('start', new \DateTimeImmutable($f['dateStart']))
            ->setParameter('end', new \DateTimeImmutable($f['dateEnd']))
            ->orderBy('e.id', 'ASC')
            ->addOrderBy('r.dateReleve', 'ASC')
            ->addOrderBy('r.id', 'ASC');

        if (!empty($f['enginId'])) {
            $qb->andWhere('e.id = :enginId')
                ->setParameter('enginId', (int) $f['enginId']);
        }

        return $qb->getQuery()->getArrayResult();
    }



    public function sumFuelLitresBetween(
        Entite $entite,
        int $enginId,
        \DateTimeInterface $previousDate,
        \DateTimeInterface $currentDate
    ): float {
        $start = \DateTimeImmutable::createFromInterface($previousDate)->setTime(0, 0, 0);
        $end = \DateTimeImmutable::createFromInterface($currentDate)->setTime(23, 59, 59);

        return
            $this->sumAlxLitres($entite, $enginId, $start, $end)
            + $this->sumTotalLitres($entite, $enginId, $start, $end)
            + $this->sumEdenredLitres($entite, $enginId, $start, $end)
            + $this->sumNoteLitres($entite, $enginId, $start, $end);
    }

    private function sumAlxLitres(Entite $entite, int $enginId, \DateTimeInterface $start, \DateTimeInterface $end): float
    {
        return (float) ($this->getEntityManager()
            ->createQueryBuilder()
            ->select('COALESCE(SUM(t.quantite), 0)')
            ->from(TransactionCarteAlx::class, 't')
            ->innerJoin('t.engin', 'e')
            ->andWhere('t.entite = :entite')
            ->andWhere('e.id = :enginId')
            ->andWhere('t.journee > :start')
            ->andWhere('t.journee <= :end')
            ->setParameter('entite', $entite)
            ->setParameter('enginId', $enginId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    private function sumTotalLitres(Entite $entite, int $enginId, \DateTimeInterface $start, \DateTimeInterface $end): float
    {
        return (float) ($this->getEntityManager()
            ->createQueryBuilder()
            ->select('COALESCE(SUM(t.quantite), 0)')
            ->from(TransactionCarteTotal::class, 't')
            ->innerJoin('t.engin', 'e')
            ->andWhere('t.entite = :entite')
            ->andWhere('e.id = :enginId')
            ->andWhere('t.dateTransaction > :start')
            ->andWhere('t.dateTransaction <= :end')
            ->setParameter('entite', $entite)
            ->setParameter('enginId', $enginId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    private function sumEdenredLitres(Entite $entite, int $enginId, \DateTimeInterface $start, \DateTimeInterface $end): float
    {
        return (float) ($this->getEntityManager()
            ->createQueryBuilder()
            ->select('COALESCE(SUM(t.quantite), 0)')
            ->from(TransactionCarteEdenred::class, 't')
            ->innerJoin('t.engin', 'e')
            ->andWhere('t.entite = :entite')
            ->andWhere('e.id = :enginId')
            ->andWhere('t.dateTransaction > :start')
            ->andWhere('t.dateTransaction <= :end')
            ->setParameter('entite', $entite)
            ->setParameter('enginId', $enginId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    private function sumNoteLitres(Entite $entite, int $enginId, \DateTimeInterface $start, \DateTimeInterface $end): float
    {
        return (float) ($this->getEntityManager()
            ->createQueryBuilder()
            ->select('COALESCE(SUM(n.quantite), 0)')
            ->from(Note::class, 'n')
            ->innerJoin('n.engin', 'e')
            ->andWhere('n.entite = :entite')
            ->andWhere('e.id = :enginId')
            ->andWhere('n.dateTransaction > :start')
            ->andWhere('n.dateTransaction <= :end')
            ->setParameter('entite', $entite)
            ->setParameter('enginId', $enginId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    private function normalizeCompteurType(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return (string) $value;
    }
}
