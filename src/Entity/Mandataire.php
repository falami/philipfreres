<?php

namespace App\Entity;

use App\Repository\MandataireRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MandataireRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Mandataire
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Entite $entite = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?Utilisateur $createur = null;

  #[ORM\Column(length: 180)]
  #[Assert\NotBlank(message: 'Le nom du mandataire est obligatoire.')]
  private ?string $nom = null;

  #[ORM\Column(length: 180, nullable: true)]
  private ?string $societe = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $logo = null;

  #[ORM\Column(length: 180, nullable: true)]
  private ?string $email = null;

  #[ORM\Column(length: 30, nullable: true)]
  private ?string $telephone = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $adresse = null;

  #[ORM\Column(length: 20, nullable: true)]
  private ?string $codePostal = null;

  #[ORM\Column(length: 120, nullable: true)]
  private ?string $ville = null;

  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $commentaire = null;

  #[ORM\Column]
  private bool $actif = true;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
  private ?\DateTimeImmutable $dateCreation = null;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
  private ?\DateTimeImmutable $updatedAt = null;

  public function __construct()
  {
    $this->dateCreation = new \DateTimeImmutable();
  }

  #[ORM\PrePersist]
  public function onPrePersist(): void
  {
    $this->dateCreation ??= new \DateTimeImmutable();
    $this->updatedAt = new \DateTimeImmutable();
  }

  #[ORM\PreUpdate]
  public function onPreUpdate(): void
  {
    $this->updatedAt = new \DateTimeImmutable();
  }

  public function __toString(): string
  {
    return trim(($this->societe ? $this->societe . ' - ' : ''));
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

  public function getCreateur(): ?Utilisateur
  {
    return $this->createur;
  }
  public function setCreateur(?Utilisateur $createur): static
  {
    $this->createur = $createur;
    return $this;
  }

  public function getNom(): ?string
  {
    return $this->nom;
  }
  public function setNom(?string $nom): static
  {
    $this->nom = trim((string) $nom);
    return $this;
  }

  public function getSociete(): ?string
  {
    return $this->societe;
  }
  public function setSociete(?string $societe): static
  {
    $this->societe = $societe ? trim($societe) : null;
    return $this;
  }

  public function getEmail(): ?string
  {
    return $this->email;
  }
  public function setEmail(?string $email): static
  {
    $this->email = $email ? trim($email) : null;
    return $this;
  }

  public function getTelephone(): ?string
  {
    return $this->telephone;
  }
  public function setTelephone(?string $telephone): static
  {
    $this->telephone = $telephone ? trim($telephone) : null;
    return $this;
  }

  public function getAdresse(): ?string
  {
    return $this->adresse;
  }
  public function setAdresse(?string $adresse): static
  {
    $this->adresse = $adresse ? trim($adresse) : null;
    return $this;
  }

  public function getCodePostal(): ?string
  {
    return $this->codePostal;
  }
  public function setCodePostal(?string $codePostal): static
  {
    $this->codePostal = $codePostal ? trim($codePostal) : null;
    return $this;
  }

  public function getVille(): ?string
  {
    return $this->ville;
  }
  public function setVille(?string $ville): static
  {
    $this->ville = $ville ? trim($ville) : null;
    return $this;
  }

  public function getCommentaire(): ?string
  {
    return $this->commentaire;
  }
  public function setCommentaire(?string $commentaire): static
  {
    $this->commentaire = $commentaire ?: null;
    return $this;
  }

  public function isActif(): bool
  {
    return $this->actif;
  }
  public function setActif(bool $actif): static
  {
    $this->actif = $actif;
    return $this;
  }

  public function getDateCreation(): ?\DateTimeImmutable
  {
    return $this->dateCreation;
  }
  public function getUpdatedAt(): ?\DateTimeImmutable
  {
    return $this->updatedAt;
  }

  public function getLogo(): ?string
  {
    return $this->logo;
  }

  public function setLogo(?string $logo): static
  {
    $this->logo = $logo;
    return $this;
  }
}
