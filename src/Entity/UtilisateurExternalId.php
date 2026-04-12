<?php
// src/Entity/UtilisateurExternalId.php

namespace App\Entity;

use App\Enum\ExternalProvider;
use App\Repository\UtilisateurExternalIdRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Service\Carburant\FuelKey;

#[ORM\Entity(repositoryClass: UtilisateurExternalIdRepository::class)]
#[ORM\Table(name: 'utilisateur_external_id')]
#[ORM\Index(columns: ['provider', 'value'], name: 'idx_user_ext_provider_value')]
#[ORM\UniqueConstraint(name: 'uniq_user_provider_value', columns: ['utilisateur_id', 'provider', 'value'])]
class UtilisateurExternalId
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(inversedBy: 'externalIds')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Utilisateur $utilisateur = null;

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
    $this->value = trim($value);
    $this->createdAt = new \DateTimeImmutable();
    $this->active = true;
  }


  public function getId(): ?int
  {
    return $this->id;
  }

  public function getUtilisateur(): ?Utilisateur
  {
    return $this->utilisateur;
  }
  public function setUtilisateur(?Utilisateur $utilisateur): self
  {
    $this->utilisateur = $utilisateur;
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
    $this->value = FuelKey::norm($value) ?? trim($value);
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
}
