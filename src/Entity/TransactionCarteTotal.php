<?php

namespace App\Entity;

use App\Repository\TransactionCarteTotalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\ExternalProvider;

#[ORM\Entity(repositoryClass: TransactionCarteTotalRepository::class)]
class TransactionCarteTotal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCarteTotals')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entite $entite = null;

    #[ORM\Column(length: 32)]
    private ?string $compteClient = null;

    #[ORM\Column(length: 255)]
    private ?string $raisonSociale = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $compteSupport = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $division = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $typeSupport = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $numeroCarte = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $rang = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $evid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomPersonnaliseCarte = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $informationComplementaire = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codeConducteur = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $immatriculationVehicule = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $nomCollaborateur = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $prenomCollaborateur = null;

    #[ORM\Column(nullable: true)]
    private ?int $kilometrage = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $numeroTransaction = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateTransaction = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $heureTransaction = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $categorieLibelleProduit = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $produit = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $statut = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $numeroFacture = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $quantite = null;

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $unite = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $prixUnitaireEur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $tauxTvaPercent = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantRemiseEur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantHtEur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantTvaEur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantTtcEur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFilename = null;

    #[ORM\Column(nullable: true)]
    private ?int $sourceRow = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $importKey = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $importedAt = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCarteTotals')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Engin $engin = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCarteTotals')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(enumType: ExternalProvider::class, options: ['default' => 'total'])]
    private ExternalProvider $provider = ExternalProvider::TOTAL;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
        $this->provider = ExternalProvider::TOTAL; // ✅ default
        $this->sourceFilename = '';
        $this->sourceRow = 0;
        $this->importKey = '';
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

    public function getCompteClient(): ?string
    {
        return $this->compteClient;
    }

    public function setCompteClient(string $compteClient): static
    {
        $this->compteClient = $compteClient;

        return $this;
    }

    public function getRaisonSociale(): ?string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(string $raisonSociale): static
    {
        $this->raisonSociale = $raisonSociale;

        return $this;
    }

    public function getCompteSupport(): ?string
    {
        return $this->compteSupport;
    }

    public function setCompteSupport(?string $compteSupport): static
    {
        $this->compteSupport = $compteSupport;

        return $this;
    }

    public function getDivision(): ?string
    {
        return $this->division;
    }

    public function setDivision(?string $division): static
    {
        $this->division = $division;

        return $this;
    }

    public function getTypeSupport(): ?string
    {
        return $this->typeSupport;
    }

    public function setTypeSupport(?string $typeSupport): static
    {
        $this->typeSupport = $typeSupport;

        return $this;
    }

    public function getNumeroCarte(): ?string
    {
        return $this->numeroCarte;
    }

    public function setNumeroCarte(?string $numeroCarte): static
    {
        $this->numeroCarte = $numeroCarte;

        return $this;
    }

    public function getRang(): ?string
    {
        return $this->rang;
    }

    public function setRang(?string $rang): static
    {
        $this->rang = $rang;

        return $this;
    }

    public function getEvid(): ?string
    {
        return $this->evid;
    }

    public function setEvid(?string $evid): static
    {
        $this->evid = $evid;

        return $this;
    }

    public function getNomPersonnaliseCarte(): ?string
    {
        return $this->nomPersonnaliseCarte;
    }

    public function setNomPersonnaliseCarte(?string $nomPersonnaliseCarte): static
    {
        $this->nomPersonnaliseCarte = $nomPersonnaliseCarte;

        return $this;
    }

    public function getInformationComplementaire(): ?string
    {
        return $this->informationComplementaire;
    }

    public function setInformationComplementaire(?string $informationComplementaire): static
    {
        $this->informationComplementaire = $informationComplementaire;

        return $this;
    }

    public function getCodeConducteur(): ?string
    {
        return $this->codeConducteur;
    }

    public function setCodeConducteur(?string $codeConducteur): static
    {
        $this->codeConducteur = $codeConducteur;

        return $this;
    }

    public function getImmatriculationVehicule(): ?string
    {
        return $this->immatriculationVehicule;
    }

    public function setImmatriculationVehicule(?string $immatriculationVehicule): static
    {
        $this->immatriculationVehicule = $immatriculationVehicule;

        return $this;
    }

    public function getNomCollaborateur(): ?string
    {
        return $this->nomCollaborateur;
    }

    public function setNomCollaborateur(?string $nomCollaborateur): static
    {
        $this->nomCollaborateur = $nomCollaborateur;

        return $this;
    }

    public function getPrenomCollaborateur(): ?string
    {
        return $this->prenomCollaborateur;
    }

    public function setPrenomCollaborateur(?string $prenomCollaborateur): static
    {
        $this->prenomCollaborateur = $prenomCollaborateur;

        return $this;
    }

    public function getKilometrage(): ?int
    {
        return $this->kilometrage;
    }

    public function setKilometrage(?int $kilometrage): static
    {
        $this->kilometrage = $kilometrage;

        return $this;
    }

    public function getNumeroTransaction(): ?string
    {
        return $this->numeroTransaction;
    }

    public function setNumeroTransaction(?string $numeroTransaction): static
    {
        $this->numeroTransaction = $numeroTransaction;

        return $this;
    }

    public function getDateTransaction(): ?\DateTimeImmutable
    {
        return $this->dateTransaction;
    }

    public function setDateTransaction(?\DateTimeImmutable $dateTransaction): static
    {
        $this->dateTransaction = $dateTransaction;

        return $this;
    }

    public function getHeureTransaction(): ?\DateTimeImmutable
    {
        return $this->heureTransaction;
    }

    public function setHeureTransaction(?\DateTimeImmutable $heureTransaction): static
    {
        $this->heureTransaction = $heureTransaction;

        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getCategorieLibelleProduit(): ?string
    {
        return $this->categorieLibelleProduit;
    }

    public function setCategorieLibelleProduit(?string $categorieLibelleProduit): static
    {
        $this->categorieLibelleProduit = $categorieLibelleProduit;

        return $this;
    }

    public function getProduit(): ?string
    {
        return $this->produit;
    }

    public function setProduit(?string $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getNumeroFacture(): ?string
    {
        return $this->numeroFacture;
    }

    public function setNumeroFacture(?string $numeroFacture): static
    {
        $this->numeroFacture = $numeroFacture;

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

    public function getUnite(): ?string
    {
        return $this->unite;
    }

    public function setUnite(?string $unite): static
    {
        $this->unite = $unite;

        return $this;
    }

    public function getPrixUnitaireEur(): ?string
    {
        return $this->prixUnitaireEur;
    }

    public function setPrixUnitaireEur(?string $prixUnitaireEur): static
    {
        $this->prixUnitaireEur = $prixUnitaireEur;

        return $this;
    }

    public function getTauxTvaPercent(): ?string
    {
        return $this->tauxTvaPercent;
    }

    public function setTauxTvaPercent(?string $tauxTvaPercent): static
    {
        $this->tauxTvaPercent = $tauxTvaPercent;

        return $this;
    }

    public function getMontantRemiseEur(): ?string
    {
        return $this->montantRemiseEur;
    }

    public function setMontantRemiseEur(?string $montantRemiseEur): static
    {
        $this->montantRemiseEur = $montantRemiseEur;

        return $this;
    }

    public function getMontantHtEur(): ?string
    {
        return $this->montantHtEur;
    }

    public function setMontantHtEur(?string $montantHtEur): static
    {
        $this->montantHtEur = $montantHtEur;

        return $this;
    }

    public function getMontantTvaEur(): ?string
    {
        return $this->montantTvaEur;
    }

    public function setMontantTvaEur(?string $montantTvaEur): static
    {
        $this->montantTvaEur = $montantTvaEur;

        return $this;
    }

    public function getMontantTtcEur(): ?string
    {
        return $this->montantTtcEur;
    }

    public function setMontantTtcEur(?string $montantTtcEur): static
    {
        $this->montantTtcEur = $montantTtcEur;

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
