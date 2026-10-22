<?php

declare(strict_types=1);
namespace App\Entity\Intescia;

use Doctrine\ORM\Mapping as ORM;

/** Internal preparation is deliberately separate from the imported market payload. */
#[ORM\Entity]
#[ORM\Table(name: 'insight_preparation')]
#[ORM\Index(name: 'idx_insight_preparation_tenant', columns: ['tenant_id'])]
class InsightPreparation
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $market_id;
    #[ORM\Column(type: 'integer')]
    public int $tenant_id;
    #[ORM\Column(type: 'integer')]
    public int $revision;
    #[ORM\Column(type: 'json')]
    public array $data;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $updated_at;
}
