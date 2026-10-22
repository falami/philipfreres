<?php
namespace App\Tests\Service;

use App\Util\FilterSelection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class FilterSelectionTest extends TestCase
{
    public function testAllNoneAndMultipleValuesRemainDistinct(): void
    {
        self::assertNull(FilterSelection::values('all'));
        self::assertNull(FilterSelection::values(''));
        self::assertSame([], FilterSelection::values('[]'));
        self::assertSame(['0'], FilterSelection::values('0'));
        self::assertSame(['1', '2'], FilterSelection::values('["1","2","1"]'));
        self::assertSame([], FilterSelection::values('[broken'));
    }

    public function testSelectionsKeepTenantConstraintAndBindValues(): void
    {
        foreach (['all', '[]', '["1","2"]'] as $selection) {
            $qb = new QueryBuilder($this->createMock(EntityManagerInterface::class));
            $qb->select('e')->from(\App\Entity\Engin::class, 'e')->where('e.entite = :tenant')->setParameter('tenant', 7);
            FilterSelection::apply($qb, 'e.id', $selection, 'ids');
            self::assertStringContainsString('e.entite = :tenant', $qb->getDQL());
            if ($selection === '[]') self::assertStringContainsString('1 = 0', $qb->getDQL());
            elseif ($selection !== 'all') {
                self::assertStringContainsString('e.id IN (:ids)', $qb->getDQL());
                self::assertSame(['1', '2'], $qb->getParameter('ids')->getValue());
            } else self::assertNull($qb->getParameter('ids'));
        }
    }
}
