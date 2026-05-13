<?php

namespace App\Entity;

use App\Repository\EnginUsageReleveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EnginUsageReleveRepository::class)]
#[ORM\Table(name: 'engin_usage_releve')]
#[ORM\Index(columns: ['entite_id', 'date_releve'], name: 'idx_usage_entite_date')]
#[ORM\Index(columns: ['engin_id', 'date_releve'], name: 'idx_usage_engin_date')]
class EnginUsageReleve
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Entite $entite = null;

  #[ORM\ManyToOne(inversedBy: 'usageReleves')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  #[Assert\NotNull(message: 'L’engin est obligatoire.')]
  private ?Engin $engin = null;

  #[ORM\Column(type: Types::DATE_IMMUTABLE)]
  #[Assert\NotNull(message: 'La date est obligatoire.')]
  private ?\DateTimeImmutable $dateReleve = null;

  #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
  #[Assert\NotBlank(message: 'La valeur est obligatoire.')]
  #[Assert\PositiveOrZero(message: 'La valeur doit être positive.')]
  private ?string $valeur = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
  private ?Utilisateur $createur = null;

  #[ORM\Column]
  private \DateTimeImmutable $createdAt;

  public function __construct()
  {
    $this->dateReleve = new \DateTimeImmutable();
    $this->createdAt = new \DateTimeImmutable();
    $this->valeur = '0.00';
  }

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getEntite(): ?Entite
  {
    return $this->entite;
  }
  public function setEntite(?Entite $entite): static
  {
    $this->entite = $entite;
    return $this;
  }

  public function getEngin(): ?Engin
  {
    return $this->engin;
  }
  public function setEngin(?Engin $engin): static
  {
    $this->engin = $engin;
    return $this;
  }

  public function getDateReleve(): ?\DateTimeImmutable
  {
    return $this->dateReleve;
  }
  public function setDateReleve(?\DateTimeImmutable $dateReleve): static
  {
    $this->dateReleve = $dateReleve;
    return $this;
  }

  public function getValeur(): ?string
  {
    return $this->valeur;
  }
  public function setValeur(string|float|int|null $valeur): static
  {
    $this->valeur = $valeur === null ? null : number_format((float) str_replace(',', '.', (string) $valeur), 2, '.', '');
    return $this;
  }


  public function getCreateur(): ?Utilisateur
  {
    return $this->createur;
  }
  public function setCreateur(?Utilisateur $createur): static
  {
    $this->createur = $createur;
    return $this;
  }

  public function getCreatedAt(): \DateTimeImmutable
  {
    return $this->createdAt;
  }
}
