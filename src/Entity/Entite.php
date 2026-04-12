<?php

namespace App\Entity;


use App\Repository\EntiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EntiteRepository::class)]
class Entite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleurPrincipal = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleurSecondaire = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleurTertiaire = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleurQuaternaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\ManyToOne(inversedBy: 'entites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(
        pattern: '/^\+?[1-9]\d{6,14}$/',
        message: 'Numéro de téléphone invalide (utilise un format international, ex: +33612345678).'
    )]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $texteAccueil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoMenu = null;

    /**
     * @var Collection<int, UtilisateurEntite>
     */
    #[ORM\OneToMany(targetEntity: UtilisateurEntite::class, mappedBy: 'entite')]
    private Collection $utilisateurEntites;

    #[ORM\Column]
    private ?bool $public = null;

    /**
     * @var Collection<int, Utilisateur>
     */
    #[ORM\OneToMany(targetEntity: Utilisateur::class, mappedBy: 'entite')]
    private Collection $responsables;

    #[ORM\Column(length: 100, nullable: false)]
    private ?string $nom = null;


    #[ORM\Column(length: 30, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $banque = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numeroTva = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $numeroCompte = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codeBanque = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $numeroDeclarant = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $formeJuridique = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fonction = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nomRepresentant = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $prenomRepresentant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    /**
     * @var Collection<int, Engin>
     */
    #[ORM\OneToMany(targetEntity: Engin::class, mappedBy: 'entite')]
    private Collection $engins;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isActive = null;

    /**
     * @var Collection<int, TransactionCarteTotal>
     */
    #[ORM\OneToMany(targetEntity: TransactionCarteTotal::class, mappedBy: 'entite')]
    private Collection $transactionCarteTotals;

    /**
     * @var Collection<int, TransactionCarteEdenred>
     */
    #[ORM\OneToMany(targetEntity: TransactionCarteEdenred::class, mappedBy: 'entite')]
    private Collection $transactionCarteEdenreds;

    /**
     * @var Collection<int, Produit>
     */
    #[ORM\OneToMany(targetEntity: Produit::class, mappedBy: 'entite')]
    private Collection $produits;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'entite')]
    private Collection $notes;


    public function __construct()
    {
        $this->utilisateurEntites = new ArrayCollection();
        $this->responsables = new ArrayCollection();
        $this->engins = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->transactionCarteTotals = new ArrayCollection();
        $this->transactionCarteEdenreds = new ArrayCollection();
        $this->produits = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCouleurPrincipal(): ?string
    {
        return $this->couleurPrincipal;
    }

    public function setCouleurPrincipal(?string $couleurPrincipal): static
    {
        $this->couleurPrincipal = $couleurPrincipal;

        return $this;
    }

    public function getCouleurSecondaire(): ?string
    {
        return $this->couleurSecondaire;
    }

    public function setCouleurSecondaire(?string $couleurSecondaire): static
    {
        $this->couleurSecondaire = $couleurSecondaire;

        return $this;
    }

    public function getCouleurTertiaire(): ?string
    {
        return $this->couleurTertiaire;
    }

    public function setCouleurTertiaire(?string $couleurTertiaire): static
    {
        $this->couleurTertiaire = $couleurTertiaire;

        return $this;
    }

    public function getCouleurQuaternaire(): ?string
    {
        return $this->couleurQuaternaire;
    }

    public function setCouleurQuaternaire(?string $couleurQuaternaire): static
    {
        $this->couleurQuaternaire = $couleurQuaternaire;

        return $this;
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

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }

    public function setCreateur(?Utilisateur $createur): static
    {
        $this->createur = $createur;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

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

    public function getComplement(): ?string
    {
        return $this->complement;
    }

    public function setComplement(?string $complement): static
    {
        $this->complement = $complement;

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

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;

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

    public function getDepartement(): ?string
    {
        return $this->departement;
    }

    public function setDepartement(?string $departement): static
    {
        $this->departement = $departement;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTexteAccueil(): ?string
    {
        return $this->texteAccueil;
    }

    public function setTexteAccueil(?string $texteAccueil): static
    {
        $this->texteAccueil = $texteAccueil;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLogoMenu(): ?string
    {
        return $this->logoMenu;
    }

    public function setLogoMenu(?string $logoMenu): static
    {
        $this->logoMenu = $logoMenu;

        return $this;
    }

    /**
     * @return Collection<int, UtilisateurEntite>
     */
    public function getUtilisateurEntites(): Collection
    {
        return $this->utilisateurEntites;
    }

    public function addUtilisateurEntite(UtilisateurEntite $utilisateurEntite): static
    {
        if (!$this->utilisateurEntites->contains($utilisateurEntite)) {
            $this->utilisateurEntites->add($utilisateurEntite);
            $utilisateurEntite->setEntite($this);
        }

        return $this;
    }

    public function removeUtilisateurEntite(UtilisateurEntite $utilisateurEntite): static
    {
        if ($this->utilisateurEntites->removeElement($utilisateurEntite)) {
            // set the owning side to null (unless already changed)
            if ($utilisateurEntite->getEntite() === $this) {
                $utilisateurEntite->setEntite(null);
            }
        }

        return $this;
    }

    public function isPublic(): ?bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): static
    {
        $this->public = $public;

        return $this;
    }

    /**
     * @return Collection<int, Utilisateur>
     */
    public function getResponsables(): Collection
    {
        return $this->responsables;
    }

    public function addResponsable(Utilisateur $responsable): static
    {
        if (!$this->responsables->contains($responsable)) {
            $this->responsables->add($responsable);
            $responsable->setEntite($this);
        }

        return $this;
    }

    public function removeResponsable(Utilisateur $responsable): static
    {
        if ($this->responsables->removeElement($responsable)) {
            // set the owning side to null (unless already changed)
            if ($responsable->getEntite() === $this) {
                $responsable->setEntite(null);
            }
        }

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }


    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }


    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $iban;

        return $this;
    }

    public function getBanque(): ?string
    {
        return $this->banque;
    }

    public function setBanque(?string $banque): static
    {
        $this->banque = $banque;

        return $this;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): static
    {
        $this->bic = $bic;

        return $this;
    }

    public function getNumeroTva(): ?string
    {
        return $this->numeroTva;
    }

    public function setNumeroTva(?string $numeroTva): static
    {
        $this->numeroTva = $numeroTva;

        return $this;
    }

    public function getNumeroCompte(): ?string
    {
        return $this->numeroCompte;
    }

    public function setNumeroCompte(?string $numeroCompte): static
    {
        $this->numeroCompte = $numeroCompte;

        return $this;
    }

    public function getCodeBanque(): ?string
    {
        return $this->codeBanque;
    }

    public function setCodeBanque(?string $codeBanque): static
    {
        $this->codeBanque = $codeBanque;

        return $this;
    }

    public function getNumeroDeclarant(): ?string
    {
        return $this->numeroDeclarant;
    }

    public function setNumeroDeclarant(?string $numeroDeclarant): static
    {
        $this->numeroDeclarant = $numeroDeclarant;

        return $this;
    }

    public function getFormeJuridique(): ?string
    {
        return $this->formeJuridique;
    }

    public function setFormeJuridique(?string $formeJuridique): static
    {
        $this->formeJuridique = $formeJuridique;

        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = $fonction;

        return $this;
    }

    public function getNomRepresentant(): ?string
    {
        return $this->nomRepresentant;
    }

    public function setNomRepresentant(?string $nomRepresentant): static
    {
        $this->nomRepresentant = $nomRepresentant;

        return $this;
    }

    public function getPrenomRepresentant(): ?string
    {
        return $this->prenomRepresentant;
    }

    public function setPrenomRepresentant(?string $prenomRepresentant): static
    {
        $this->prenomRepresentant = $prenomRepresentant;

        return $this;
    }

    /**
     * @return Collection<int, Engin>
     */
    public function getEngins(): Collection
    {
        return $this->engins;
    }

    public function addEngin(Engin $engin): static
    {
        if (!$this->engins->contains($engin)) {
            $this->engins->add($engin);
            $engin->setEntite($this);
        }

        return $this;
    }

    public function removeEngin(Engin $engin): static
    {
        if ($this->engins->removeElement($engin)) {
            // set the owning side to null (unless already changed)
            if ($engin->getEntite() === $this) {
                $engin->setEntite(null);
            }
        }

        return $this;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function touchActivity(): void
    {
        $this->lastActivityAt = new \DateTimeImmutable();
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(?bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function setLastActivityAt(?\DateTimeImmutable $dt): static
    {
        $this->lastActivityAt = $dt;
        return $this;
    }

    /**
     * @return Collection<int, TransactionCarteTotal>
     */
    public function getTransactionCarteTotals(): Collection
    {
        return $this->transactionCarteTotals;
    }

    public function addTransactionCarteTotal(TransactionCarteTotal $transactionCarteTotal): static
    {
        if (!$this->transactionCarteTotals->contains($transactionCarteTotal)) {
            $this->transactionCarteTotals->add($transactionCarteTotal);
            $transactionCarteTotal->setEntite($this);
        }

        return $this;
    }

    public function removeTransactionCarteTotal(TransactionCarteTotal $transactionCarteTotal): static
    {
        if ($this->transactionCarteTotals->removeElement($transactionCarteTotal)) {
            // set the owning side to null (unless already changed)
            if ($transactionCarteTotal->getEntite() === $this) {
                $transactionCarteTotal->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TransactionCarteEdenred>
     */
    public function getTransactionCarteEdenreds(): Collection
    {
        return $this->transactionCarteEdenreds;
    }

    public function addTransactionCarteEdenred(TransactionCarteEdenred $transactionCarteEdenred): static
    {
        if (!$this->transactionCarteEdenreds->contains($transactionCarteEdenred)) {
            $this->transactionCarteEdenreds->add($transactionCarteEdenred);
            $transactionCarteEdenred->setEntite($this);
        }

        return $this;
    }

    public function removeTransactionCarteEdenred(TransactionCarteEdenred $transactionCarteEdenred): static
    {
        if ($this->transactionCarteEdenreds->removeElement($transactionCarteEdenred)) {
            // set the owning side to null (unless already changed)
            if ($transactionCarteEdenred->getEntite() === $this) {
                $transactionCarteEdenred->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Produit>
     */
    public function getProduits(): Collection
    {
        return $this->produits;
    }

    public function addProduit(Produit $produit): static
    {
        if (!$this->produits->contains($produit)) {
            $this->produits->add($produit);
            $produit->setEntite($this);
        }

        return $this;
    }

    public function removeProduit(Produit $produit): static
    {
        if ($this->produits->removeElement($produit)) {
            // set the owning side to null (unless already changed)
            if ($produit->getEntite() === $this) {
                $produit->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Note>
     */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function addNote(Note $note): static
    {
        if (!$this->notes->contains($note)) {
            $this->notes->add($note);
            $note->setEntite($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getEntite() === $this) {
                $note->setEntite(null);
            }
        }

        return $this;
    }
}
