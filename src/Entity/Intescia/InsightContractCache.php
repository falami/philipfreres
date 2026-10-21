<?php

declare(strict_types=1);

namespace App\Entity\Intescia;

use Doctrine\ORM\Mapping as ORM;

/** Persistence mapping; writes are handled atomically by InsightStore. */
#[ORM\Entity]
#[ORM\Table(name: "insight_contract_cache")]
#[ORM\Index(name: "idx_insight_contract_cache_tenant", columns: ["tenant_id"])]
class InsightContractCache
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 64)]
    public string $id;
    #[ORM\Column(type: "integer")]
    public int $tenant_id;
    #[ORM\Column(type: "json")]
    public array $payload;
}
