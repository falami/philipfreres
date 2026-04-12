<?php
// src/Entity/EnginExternalId.php

namespace App\Entity;

use App\Enum\ExternalProvider;
use App\Repository\EnginExternalIdRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Service\Carburant\FuelKey;

#[ORM\Entity(repositoryClass: EnginExternalIdRepository::class)]
#[ORM\Table(name: 'engin_external_id')]
#[ORM\Index(columns: ['provider', 'value'], name: 'idx_engin_ext_provider_value')]
#[ORM\UniqueConstraint(name: 'uniq_engin_provider_value', columns: ['engin_id', 'provider', 'value'])]
class EnginExternalId
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(inversedBy: 'externalIds')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Engin $engin = null;

  #[ORM\Column(enumType: ExternalProvider::class)]
  private ExternalProvider $provider;

  #[ORM\Column(length: 255)]
  #[Assert\NotBlank]
  private string $value;

  #[ORM\Column(options: ['default' => true])]
  private bool $active = true;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
  private \DateTimeImmutable $createdAt;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
  private ?\DateTimeImmutable $disabledAt = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $note = null;

  public function __construct(ExternalProvider $provider, string $value)
  {
    $this->provider = $provider;
    $this->value = FuelKey::norm($value) ?? trim($value); // ✅
    $this->createdAt = new \DateTimeImmutable();
    $this->active = true;
  }

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getEngin(): ?Engin
  {
    return $this->engin;
  }
  public function setEngin(?Engin $engin): self
  {
    $this->engin = $engin;
    return $this;
  }

  public function getProvider(): ExternalProvider
  {
    return $this->provider;
  }
  public function setProvider(ExternalProvider $provider): self
  {
    $this->provider = $provider;
    return $this;
  }

  public function getValue(): string
  {
    return $this->value;
  }
  public function setValue(string $value): self
  {
    $norm = FuelKey::norm($value) ?? trim($value);
    if ($norm === '') {
      throw new \InvalidArgumentException('External ID value cannot be empty.');
    }
    $this->value = $norm;
    return $this;
  }

  public function isActive(): bool
  {
    return $this->active;
  }

  public function disable(?string $note = null): self
  {
    $this->active = false;
    $this->disabledAt = new \DateTimeImmutable();
    if ($note !== null) $this->note = $note;
    return $this;
  }

  public function getCreatedAt(): \DateTimeImmutable
  {
    return $this->createdAt;
  }
  public function getDisabledAt(): ?\DateTimeImmutable
  {
    return $this->disabledAt;
  }

  public function getNote(): ?string
  {
    return $this->note;
  }
  public function setNote(?string $note): self
  {
    $this->note = $note;
    return $this;
  }

  public function enable(): self
  {
    $this->active = true;
    $this->disabledAt = null;
    return $this;
  }
}
