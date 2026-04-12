<?php
// src/Entity/TransactionCarteAlx.php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionCarteAlxRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\ExternalProvider;

#[ORM\Entity(repositoryClass: TransactionCarteAlxRepository::class)]
#[ORM\Table(name: 'transaction_carte_alx')]
#[ORM\UniqueConstraint(name: 'uniq_alx_entite_import_key', columns: ['entite_id', 'import_key'])]
class TransactionCarteAlx
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(inversedBy: 'transactionCarteAlxes')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Entite $entite = null;

  #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
  private ?\DateTimeImmutable $journee = null;

  #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
  private ?\DateTimeImmutable $horaire = null;

  #[ORM\Column(length: 180, nullable: true)]
  private ?string $vehicule = null;

  #[ORM\Column(length: 40, nullable: true)]
  private ?string $codeVeh = null;

  #[ORM\Column(length: 40, nullable: true)]
  private ?string $codeAgent = null;

  #[ORM\Column(length: 180, nullable: true)]
  private ?string $agent = null;

  #[ORM\Column(nullable: true)]
  private ?int $operation = null;

  #[ORM\Column(nullable: true)]
  private ?int $cuve = null;

  #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
  private ?string $quantite = null;

  #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
  private ?string $prixUnitaire = null;

  #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 0, nullable: true)]
  private ?string $compteur = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $sourceFilename = null;

  #[ORM\Column(nullable: true)]
  private ?int $sourceRow = null;

  #[ORM\Column(name: 'import_key', length: 40, nullable: true)]
  private ?string $importKey = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $importedAt = null;

  #[ORM\ManyToOne(inversedBy: 'transactionCarteAlxes')]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?Engin $engin = null;

  #[ORM\ManyToOne(inversedBy: 'transactionCarteAlxes')]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?Utilisateur $utilisateur = null;


  #[ORM\Column(enumType: ExternalProvider::class, options: ['default' => 'alx'])]
  private ExternalProvider $provider = ExternalProvider::ALX;

  public function __construct()
  {
    $this->importedAt = new \DateTimeImmutable();
    $this->provider = ExternalProvider::ALX; // ✅ default
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

  public function getJournee(): ?\DateTimeImmutable
  {
    return $this->journee;
  }
  public function setJournee(?\DateTimeImmutable $journee): static
  {
    $this->journee = $journee;
    return $this;
  }

  public function getHoraire(): ?\DateTimeImmutable
  {
    return $this->horaire;
  }
  public function setHoraire(?\DateTimeImmutable $horaire): static
  {
    $this->horaire = $horaire;
    return $this;
  }

  public function getVehicule(): ?string
  {
    return $this->vehicule;
  }
  public function setVehicule(?string $vehicule): static
  {
    $this->vehicule = $vehicule;
    return $this;
  }

  public function getCodeVeh(): ?string
  {
    return $this->codeVeh;
  }
  public function setCodeVeh(?string $codeVeh): static
  {
    $this->codeVeh = $codeVeh;
    return $this;
  }

  public function getCodeAgent(): ?string
  {
    return $this->codeAgent;
  }
  public function setCodeAgent(?string $codeAgent): static
  {
    $this->codeAgent = $codeAgent;
    return $this;
  }

  public function getAgent(): ?string
  {
    return $this->agent;
  }
  public function setAgent(?string $agent): static
  {
    $this->agent = $agent;
    return $this;
  }

  public function getOperation(): ?int
  {
    return $this->operation;
  }
  public function setOperation(?int $operation): static
  {
    $this->operation = $operation;
    return $this;
  }

  public function getCuve(): ?int
  {
    return $this->cuve;
  }
  public function setCuve(?int $cuve): static
  {
    $this->cuve = $cuve;
    return $this;
  }

  public function getQuantite(): ?string
  {
    return $this->quantite;
  }
  public function setQuantite(?string $quantite): static
  {
    $this->quantite = $quantite;
    return $this;
  }

  public function getPrixUnitaire(): ?string
  {
    return $this->prixUnitaire;
  }
  public function setPrixUnitaire(?string $prixUnitaire): static
  {
    $this->prixUnitaire = $prixUnitaire;
    return $this;
  }

  public function getCompteur(): ?string
  {
    return $this->compteur;
  }
  public function setCompteur(?string $compteur): static
  {
    $this->compteur = $compteur;
    return $this;
  }

  public function getSourceFilename(): ?string
  {
    return $this->sourceFilename;
  }
  public function setSourceFilename(?string $sourceFilename): static
  {
    $this->sourceFilename = $sourceFilename;
    return $this;
  }

  public function getSourceRow(): ?int
  {
    return $this->sourceRow;
  }
  public function setSourceRow(?int $sourceRow): static
  {
    $this->sourceRow = $sourceRow;
    return $this;
  }

  public function getImportKey(): ?string
  {
    return $this->importKey;
  }
  public function setImportKey(?string $importKey): static
  {
    $this->importKey = $importKey;
    return $this;
  }

  public function getImportedAt(): ?\DateTimeImmutable
  {
    return $this->importedAt;
  }
  public function setImportedAt(?\DateTimeImmutable $importedAt): static
  {
    $this->importedAt = $importedAt;
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

  public function getUtilisateur(): ?Utilisateur
  {
    return $this->utilisateur;
  }

  public function setUtilisateur(?Utilisateur $utilisateur): static
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
}
