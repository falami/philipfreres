<?php

declare(strict_types=1);

namespace App\Entity\Intescia;

use Doctrine\ORM\Mapping as ORM;

/** Persistence mapping; writes are handled atomically by InsightStore. */
#[ORM\Entity]
#[ORM\Table(name: "insight_digest_delivery")]
#[ORM\Index(name: "idx_insight_digest_delivery_tenant", columns: ["tenant_id"])]
class InsightDigestDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 64)]
    public string $id;
    #[ORM\Column(type: "integer")]
    public int $tenant_id;
    #[ORM\Column(type: "integer")]
    public int $user_id;
    #[ORM\Column(type: "string", length: 10)]
    public string $scheduled_date;
    #[ORM\Column(type: "string", length: 20)]
    public string $status;
    #[ORM\Column(type: "datetime_immutable")]
    public \DateTimeImmutable $created_at;
    #[ORM\Column(type: "datetime_immutable")]
    public \DateTimeImmutable $updated_at;
}
