<?php

declare(strict_types=1);

namespace App\Entity\Intescia;

use Doctrine\ORM\Mapping as ORM;

/** Persistence mapping; writes are handled atomically by InsightStore. */
#[ORM\Entity]
#[ORM\Table(name: "insight_market")]
#[ORM\Index(name: "idx_insight_market_tenant", columns: ["tenant_id"])]
#[ORM\Index(name: "idx_insight_market_due", columns: ["tenant_id", "active", "deadline"])]
class InsightMarket
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 64)]
    public string $id;
    #[ORM\Column(type: "integer")]
    public int $tenant_id;
    #[ORM\Column(type: "string", length: 36)]
    public string $remote_id;
    #[ORM\Column(type: "text")]
    public string $title;
    #[ORM\Column(type: "text")]
    public string $buyer;
    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    public ?\DateTimeImmutable $deadline;
    #[ORM\Column(type: "boolean")]
    public bool $active;
    #[ORM\Column(type: "string", length: 32)]
    public string $seen;
    #[ORM\Column(type: "json")]
    public array $payload;
    #[ORM\Column(type: "datetime_immutable")]
    public \DateTimeImmutable $updated_at;
}
