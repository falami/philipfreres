<?php

namespace App\Entity;

use App\Repository\TransactionCarteEdenredRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\ExternalProvider;

#[ORM\Entity(repositoryClass: TransactionCarteEdenredRepository::class)]
// Dans TransactionCarteEdenred (recommandé)
#[ORM\Table(name: 'transaction_carte_edenred')]
#[ORM\UniqueConstraint(name: 'uniq_edenred_entite_import', columns: ['entite_id', 'import_key'])]
class TransactionCarteEdenred
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCarteEdenreds')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entite $entite = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $importKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFilename = null;

    #[ORM\Column(nullable: true)]
    private ?int $sourceRow = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $importedAt = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $enseigne = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $siteCodeSite = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $siteNumeroTerminal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteLibelle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteLibelleCourt = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $siteType = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $clientReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientNom = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $carteType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $carteNumero = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $carteValidite = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $numeroTlc = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateTelecollecte = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $typeTransaction = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $numeroTransaction = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateTransaction = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $referenceTransaction = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $codeDevise = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $codeProduit = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $produit = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $prixUnitaire = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $quantite = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantTtc = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantHt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codeVehicule = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codeChauffeur = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $kilometrage = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $immatriculation = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $codeReponse = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $numeroOpposition = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $numeroAutorisation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAutorisation = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $modeTransaction = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $modeVente = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $modeValidation = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $facturationClient = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $facturationSite = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $soldeApres = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $numeroFacture = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $avoirGerant = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCarteEdenreds')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Engin $engin = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCarteEdenreds')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(enumType: ExternalProvider::class, options: ['default' => 'edenred'])]
    private ExternalProvider $provider = ExternalProvider::EDENRED;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
        $this->provider = ExternalProvider::EDENRED; // ✅ default
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

    public function getImportKey(): ?string
    {
        return $this->importKey;
    }

    public function setImportKey(?string $importKey): static
    {
        $this->importKey = $importKey;

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

    public function getImportedAt(): ?\DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(?\DateTimeImmutable $importedAt): static
    {
        $this->importedAt = $importedAt;

        return $this;
    }

    public function getEnseigne(): ?string
    {
        return $this->enseigne;
    }

    public function setEnseigne(?string $enseigne): static
    {
        $this->enseigne = $enseigne;

        return $this;
    }

    public function getSiteCodeSite(): ?string
    {
        return $this->siteCodeSite;
    }

    public function setSiteCodeSite(?string $siteCodeSite): static
    {
        $this->siteCodeSite = $siteCodeSite;

        return $this;
    }

    public function getSiteNumeroTerminal(): ?string
    {
        return $this->siteNumeroTerminal;
    }

    public function setSiteNumeroTerminal(?string $siteNumeroTerminal): static
    {
        $this->siteNumeroTerminal = $siteNumeroTerminal;

        return $this;
    }

    public function getSiteLibelle(): ?string
    {
        return $this->siteLibelle;
    }

    public function setSiteLibelle(?string $siteLibelle): static
    {
        $this->siteLibelle = $siteLibelle;

        return $this;
    }

    public function getSiteLibelleCourt(): ?string
    {
        return $this->siteLibelleCourt;
    }

    public function setSiteLibelleCourt(?string $siteLibelleCourt): static
    {
        $this->siteLibelleCourt = $siteLibelleCourt;

        return $this;
    }

    public function getSiteType(): ?string
    {
        return $this->siteType;
    }

    public function setSiteType(?string $siteType): static
    {
        $this->siteType = $siteType;

        return $this;
    }

    public function getClientReference(): ?string
    {
        return $this->clientReference;
    }

    public function setClientReference(?string $clientReference): static
    {
        $this->clientReference = $clientReference;

        return $this;
    }

    public function getClientNom(): ?string
    {
        return $this->clientNom;
    }

    public function setClientNom(?string $clientNom): static
    {
        $this->clientNom = $clientNom;

        return $this;
    }

    public function getCarteType(): ?string
    {
        return $this->carteType;
    }

    public function setCarteType(?string $carteType): static
    {
        $this->carteType = $carteType;

        return $this;
    }

    public function getCarteNumero(): ?string
    {
        return $this->carteNumero;
    }

    public function setCarteNumero(?string $carteNumero): static
    {
        $this->carteNumero = $carteNumero;

        return $this;
    }

    public function getCarteValidite(): ?string
    {
        return $this->carteValidite;
    }

    public function setCarteValidite(?string $carteValidite): static
    {
        $this->carteValidite = $carteValidite;

        return $this;
    }

    public function getNumeroTlc(): ?string
    {
        return $this->numeroTlc;
    }

    public function setNumeroTlc(?string $numeroTlc): static
    {
        $this->numeroTlc = $numeroTlc;

        return $this;
    }

    public function getDateTelecollecte(): ?\DateTimeImmutable
    {
        return $this->dateTelecollecte;
    }

    public function setDateTelecollecte(?\DateTimeImmutable $dateTelecollecte): static
    {
        $this->dateTelecollecte = $dateTelecollecte;

        return $this;
    }

    public function getTypeTransaction(): ?string
    {
        return $this->typeTransaction;
    }

    public function setTypeTransaction(?string $typeTransaction): static
    {
        $this->typeTransaction = $typeTransaction;

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

    public function getReferenceTransaction(): ?string
    {
        return $this->referenceTransaction;
    }

    public function setReferenceTransaction(?string $referenceTransaction): static
    {
        $this->referenceTransaction = $referenceTransaction;

        return $this;
    }

    public function getCodeDevise(): ?string
    {
        return $this->codeDevise;
    }

    public function setCodeDevise(?string $codeDevise): static
    {
        $this->codeDevise = $codeDevise;

        return $this;
    }

    public function getCodeProduit(): ?string
    {
        return $this->codeProduit;
    }

    public function setCodeProduit(?string $codeProduit): static
    {
        $this->codeProduit = $codeProduit;

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

    public function getPrixUnitaire(): ?string
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(?string $prixUnitaire): static
    {
        $this->prixUnitaire = $prixUnitaire;

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

    public function getMontantTtc(): ?string
    {
        return $this->montantTtc;
    }

    public function setMontantTtc(?string $montantTtc): static
    {
        $this->montantTtc = $montantTtc;

        return $this;
    }

    public function getMontantHt(): ?string
    {
        return $this->montantHt;
    }

    public function setMontantHt(?string $montantHt): static
    {
        $this->montantHt = $montantHt;

        return $this;
    }

    public function getCodeVehicule(): ?string
    {
        return $this->codeVehicule;
    }

    public function setCodeVehicule(?string $codeVehicule): static
    {
        $this->codeVehicule = $codeVehicule;

        return $this;
    }

    public function getCodeChauffeur(): ?string
    {
        return $this->codeChauffeur;
    }

    public function setCodeChauffeur(?string $codeChauffeur): static
    {
        $this->codeChauffeur = $codeChauffeur;

        return $this;
    }

    public function getKilometrage(): ?string
    {
        return $this->kilometrage;
    }

    public function setKilometrage(?string $kilometrage): static
    {
        $this->kilometrage = $kilometrage;

        return $this;
    }

    public function getImmatriculation(): ?string
    {
        return $this->immatriculation;
    }

    public function setImmatriculation(?string $immatriculation): static
    {
        $this->immatriculation = $immatriculation;

        return $this;
    }

    public function getCodeReponse(): ?string
    {
        return $this->codeReponse;
    }

    public function setCodeReponse(?string $codeReponse): static
    {
        $this->codeReponse = $codeReponse;

        return $this;
    }

    public function getNumeroOpposition(): ?string
    {
        return $this->numeroOpposition;
    }

    public function setNumeroOpposition(?string $numeroOpposition): static
    {
        $this->numeroOpposition = $numeroOpposition;

        return $this;
    }

    public function getNumeroAutorisation(): ?string
    {
        return $this->numeroAutorisation;
    }

    public function setNumeroAutorisation(?string $numeroAutorisation): static
    {
        $this->numeroAutorisation = $numeroAutorisation;

        return $this;
    }

    public function getMotifAutorisation(): ?string
    {
        return $this->motifAutorisation;
    }

    public function setMotifAutorisation(?string $motifAutorisation): static
    {
        $this->motifAutorisation = $motifAutorisation;

        return $this;
    }

    public function getModeTransaction(): ?string
    {
        return $this->modeTransaction;
    }

    public function setModeTransaction(?string $modeTransaction): static
    {
        $this->modeTransaction = $modeTransaction;

        return $this;
    }

    public function getModeVente(): ?string
    {
        return $this->modeVente;
    }

    public function setModeVente(?string $modeVente): static
    {
        $this->modeVente = $modeVente;

        return $this;
    }

    public function getModeValidation(): ?string
    {
        return $this->modeValidation;
    }

    public function setModeValidation(?string $modeValidation): static
    {
        $this->modeValidation = $modeValidation;

        return $this;
    }

    public function getFacturationClient(): ?string
    {
        return $this->facturationClient;
    }

    public function setFacturationClient(?string $facturationClient): static
    {
        $this->facturationClient = $facturationClient;

        return $this;
    }

    public function getFacturationSite(): ?string
    {
        return $this->facturationSite;
    }

    public function setFacturationSite(?string $facturationSite): static
    {
        $this->facturationSite = $facturationSite;

        return $this;
    }

    public function getSoldeApres(): ?string
    {
        return $this->soldeApres;
    }

    public function setSoldeApres(?string $soldeApres): static
    {
        $this->soldeApres = $soldeApres;

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

    public function getAvoirGerant(): ?string
    {
        return $this->avoirGerant;
    }

    public function setAvoirGerant(?string $avoirGerant): static
    {
        $this->avoirGerant = $avoirGerant;

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
