<?php

namespace App\Entity;

use App\Enum\ExternalProvider;
use App\Repository\ProduitExternalIdRepository;
use App\Service\Carburant\FuelKey;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProduitExternalIdRepository::class)]
#[ORM\Table(name: 'produit_external_id')]
#[ORM\Index(columns: ['provider', 'value'], name: 'idx_typedep_ext_provider_value')]
#[ORM\UniqueConstraint(name: 'uniq_typedep_provider_value', columns: ['produit_id', 'provider', 'value'])]
class ProduitExternalId
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(inversedBy: 'externalIds')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Produit $produit = null;

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
    $this->value = FuelKey::norm($value) ?? trim($value);
    $this->createdAt = new \DateTimeImmutable();
    $this->active = true;
  }

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getProduit(): ?Produit
  {
    return $this->produit;
  }

  public function setProduit(?Produit $produit): self
  {
    $this->produit = $produit;
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
}
