<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Server-side persistence for Symfony's rotating remember-me cookies.
 * Authentication writes go through DBAL to avoid stale managed entities.
 */
#[ORM\Entity]
#[ORM\Table(name: 'remembered_device')]
#[ORM\Index(name: 'idx_remembered_device_username', columns: ['username'])]
#[ORM\Index(name: 'idx_remembered_device_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_remembered_device_last_used', columns: ['last_used'])]
class RememberedDevice
{
    #[ORM\Id]
    #[ORM\Column(length: 88)]
    private string $series;

    #[ORM\Column(length: 180)]
    private string $username;

    #[ORM\Column(length: 64)]
    private string $validatorHash;

    /** @var list<string> Hashes issued by concurrent rotations in this generation. */
    #[ORM\Column(type: Types::JSON)]
    private array $siblingValidatorHashes = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $previousValidatorHash = null;

    /** @var list<string> The previous generation, accepted only during its grace period. */
    #[ORM\Column(type: Types::JSON)]
    private array $previousSiblingValidatorHashes = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $previousValidUntil = null;

    #[ORM\Column(length: 64)]
    private string $credentialFingerprint;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastUsed;
}
