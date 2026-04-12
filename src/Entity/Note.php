<?php

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteRepository::class)]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateTransaction = null;

    #[ORM\Column(length: 255)]
    private ?string $libelle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $quantite = null;

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

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Engin $engin = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Produit $produit = null;

    public function __construct()
    {
        $this->dateTransaction = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;
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

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;
        return $this;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;
        return $this;
    }

    public function getProduitLabel(): ?string
    {
        if (!$this->produit) {
            return null;
        }

        return $this->produit->getCategorieProduit()->label() . ' — ' . $this->produit->getSousCategorieProduit()->label();
    }
}
