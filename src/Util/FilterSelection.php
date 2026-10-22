<?php

namespace App\Util;

use Doctrine\ORM\QueryBuilder;

/** Scalar legacy filters and JSON multi-selections share the same request field. */
final class FilterSelection
{
    /** null means unrestricted; [] explicitly means no result. */
    public static function values(?string $value): ?array
    {
        if ($value === null || $value === '' || $value === 'all') return null;
        if (str_starts_with($value, '[')) {
            $values = json_decode($value, true);
            if (!is_array($values)) return [];
            return array_values(array_unique(array_map('strval', array_filter($values, 'is_scalar'))));
        }
        return [$value];
    }

    public static function apply(QueryBuilder $qb, string $field, ?string $value, string $parameter): void
    {
        $values = self::values($value);
        if ($values === null) return;
        if ($values === []) { $qb->andWhere('1 = 0'); return; }
        $qb->andWhere($field.' IN (:'.$parameter.')')->setParameter($parameter, $values);
    }
}
