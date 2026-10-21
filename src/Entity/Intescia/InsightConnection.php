<?php

declare(strict_types=1);

namespace App\Entity\Intescia;

use Doctrine\ORM\Mapping as ORM;

/** Persistence mapping; writes are handled atomically by InsightStore. */
#[ORM\Entity]
#[ORM\Table(name: "insight_connection")]
class InsightConnection
{
    #[ORM\Id]
    #[ORM\Column(type: "integer")]
    public int $tenant_id;
    #[ORM\Column(type: "json")]
    public array $settings;
    #[ORM\Column(type: "text", nullable: true)]
    public ?string $credentials;
}
