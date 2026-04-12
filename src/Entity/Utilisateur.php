<?php

namespace App\Entity;

use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\UtilisateurExternalId;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private bool $isVerified = false;

    /**
     * @var Collection<int, Entite>
     */
    #[ORM\OneToMany(targetEntity: Entite::class, mappedBy: 'createur')]
    private Collection $entites;

    /**
     * @var Collection<int, UtilisateurEntite>
     */
    #[ORM\OneToMany(
        mappedBy: 'utilisateur',
        targetEntity: UtilisateurEntite::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $utilisateurEntites;


    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    private ?string $prenom = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'utilisateursCreateur')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $createur = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'createur')]
    private Collection $utilisateursCreateur;

    #[ORM\ManyToOne(inversedBy: 'responsables')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $civilite = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $abonnement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $numeroLicence = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(nullable: true)]
    private ?bool $desactiverTemporairement = null;

    #[ORM\Column(nullable: true)]
    private ?bool $bannir = null;

    #[ORM\Column(nullable: true)]
    private ?int $unreadCount = null;

    #[ORM\Column(nullable: true)]
    private ?bool $consentementRgpd = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateConsentementRgpd = null;

    #[ORM\Column(nullable: true)]
    private ?bool $newsletter = null;

    #[ORM\Column(nullable: true)]
    private ?bool $mailBienvenue = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $niveau = null;

    #[ORM\Column(nullable: true)]
    private ?bool $mailSortie = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $societe = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;


    /**
     * @var Collection<int, Engin>
     */
    #[ORM\OneToMany(targetEntity: Engin::class, mappedBy: 'createur')]
    private Collection $enginCreateurs;
    /**
     * @var Collection<int, UtilisateurEntite>
     */
    #[ORM\OneToMany(targetEntity: UtilisateurEntite::class, mappedBy: 'createur')]
    private Collection $utilisateurEntiteCreateurs;

    /**
     * @var Collection<int, TransactionCarteAlx>
     */
    #[ORM\OneToMany(targetEntity: TransactionCarteAlx::class, mappedBy: 'utilisateur')]
    private Collection $transactionCarteAlxes;

    /**
     * @var Collection<int, TransactionCarteEdenred>
     */
    #[ORM\OneToMany(targetEntity: TransactionCarteEdenred::class, mappedBy: 'utilisateur')]
    private Collection $transactionCarteEdenreds;

    /**
     * @var Collection<int, TransactionCarteTotal>
     */
    #[ORM\OneToMany(targetEntity: TransactionCarteTotal::class, mappedBy: 'utilisateur')]
    private Collection $transactionCarteTotals;


    #[ORM\OneToMany(
        targetEntity: UtilisateurExternalId::class,
        mappedBy: 'utilisateur',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $externalIds;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'utilisateur')]
    private Collection $notes;


    public function __construct()
    {
        $this->entites = new ArrayCollection();
        $this->utilisateurEntites = new ArrayCollection();
        $this->utilisateursCreateur = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->enginCreateurs = new ArrayCollection();
        $this->utilisateurEntiteCreateurs = new ArrayCollection();
        $this->transactionCarteAlxes = new ArrayCollection();
        $this->transactionCarteEdenreds = new ArrayCollection();
        $this->transactionCarteTotals = new ArrayCollection();
        $this->externalIds = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_values(array_unique($roles));
    }

    public function isSuperAdmin(): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $this->getRoles(), true);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    /**
     * @return Collection<int, Entite>
     */
    public function getEntites(): Collection
    {
        return $this->entites;
    }

    public function addEntite(Entite $entite): static
    {
        if (!$this->entites->contains($entite)) {
            $this->entites->add($entite);
            $entite->setCreateur($this);
        }

        return $this;
    }

    public function removeEntite(Entite $entite): static
    {
        if ($this->entites->removeElement($entite)) {
            // set the owning side to null (unless already changed)
            if ($entite->getCreateur() === $this) {
                $entite->setCreateur(null);
            }
        }

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
            $utilisateurEntite->setUtilisateur($this);
        }

        return $this;
    }

    public function removeUtilisateurEntite(UtilisateurEntite $utilisateurEntite): static
    {
        if ($this->utilisateurEntites->removeElement($utilisateurEntite)) {
            // set the owning side to null (unless already changed)
            if ($utilisateurEntite->getUtilisateur() === $this) {
                $utilisateurEntite->setUtilisateur(null);
            }
        }

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

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

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;

        return $this;
    }

    public function getCreateur(): ?self
    {
        return $this->createur;
    }

    public function setCreateur(?self $createur): static
    {
        $this->createur = $createur;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getUtilisateursCreateur(): Collection
    {
        return $this->utilisateursCreateur;
    }

    public function addUtilisateursCreateur(self $utilisateursCreateur): static
    {
        if (!$this->utilisateursCreateur->contains($utilisateursCreateur)) {
            $this->utilisateursCreateur->add($utilisateursCreateur);
            $utilisateursCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeUtilisateursCreateur(self $utilisateursCreateur): static
    {
        if ($this->utilisateursCreateur->removeElement($utilisateursCreateur)) {
            // set the owning side to null (unless already changed)
            if ($utilisateursCreateur->getCreateur() === $this) {
                $utilisateursCreateur->setCreateur(null);
            }
        }

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

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

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

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

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

    public function getCivilite(): ?string
    {
        return $this->civilite;
    }

    public function setCivilite(?string $civilite): static
    {
        $this->civilite = $civilite;

        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): static
    {
        $this->resetToken = $resetToken;

        return $this;
    }


    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): static
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;

        return $this;
    }

    public function getAbonnement(): ?string
    {
        return $this->abonnement;
    }

    public function setAbonnement(?string $abonnement): static
    {
        $this->abonnement = $abonnement;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getNumeroLicence(): ?string
    {
        return $this->numeroLicence;
    }

    public function setNumeroLicence(?string $numeroLicence): static
    {
        $this->numeroLicence = $numeroLicence;

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

    public function isDesactiverTemporairement(): ?bool
    {
        return $this->desactiverTemporairement;
    }

    public function setDesactiverTemporairement(bool $desactiverTemporairement): static
    {
        $this->desactiverTemporairement = $desactiverTemporairement;

        return $this;
    }

    public function isBannir(): ?bool
    {
        return $this->bannir;
    }

    public function setBannir(bool $bannir): static
    {
        $this->bannir = $bannir;

        return $this;
    }

    public function getUnreadCount(): ?int
    {
        return $this->unreadCount;
    }

    public function setUnreadCount(?int $unreadCount): static
    {
        $this->unreadCount = $unreadCount;

        return $this;
    }

    public function isConsentementRgpd(): ?bool
    {
        return $this->consentementRgpd;
    }

    public function setConsentementRgpd(?bool $consentementRgpd): static
    {
        $this->consentementRgpd = $consentementRgpd;

        return $this;
    }

    public function getDateConsentementRgpd(): ?\DateTimeImmutable
    {
        return $this->dateConsentementRgpd;
    }

    public function setDateConsentementRgpd(?\DateTimeImmutable $dateConsentementRgpd): static
    {
        $this->dateConsentementRgpd = $dateConsentementRgpd;

        return $this;
    }

    public function isNewsletter(): ?bool
    {
        return $this->newsletter;
    }

    public function setNewsletter(?bool $newsletter): static
    {
        $this->newsletter = $newsletter;

        return $this;
    }

    public function isMailBienvenue(): ?bool
    {
        return $this->mailBienvenue;
    }

    public function setMailBienvenue(?bool $mailBienvenue): static
    {
        $this->mailBienvenue = $mailBienvenue;

        return $this;
    }

    public function getNiveau(): ?string
    {
        return $this->niveau;
    }

    public function setNiveau(?string $niveau): static
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function isMailSortie(): ?bool
    {
        return $this->mailSortie;
    }

    public function setMailSortie(?bool $mailSortie): static
    {
        $this->mailSortie = $mailSortie;

        return $this;
    }


    public function getSociete(): ?string
    {
        return $this->societe;
    }

    public function setSociete(?string $societe): static
    {
        $this->societe = $societe;

        return $this;
    }


    public function __toString(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function getAdresseComplete(): ?string
    {
        $parts = array_filter([
            $this->adresse,
            $this->complement,
            $this->codePostal,
            $this->ville,
            $this->pays,
        ], static fn($v) => is_string($v) && trim($v) !== '');

        $full = trim(implode(', ', array_map('trim', $parts)));

        return $full !== '' ? $full : null;
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


    /**
     * @return Collection<int, Engin>
     */
    public function getEnginCreateurs(): Collection
    {
        return $this->enginCreateurs;
    }

    public function addEnginCreateur(Engin $enginCreateur): static
    {
        if (!$this->enginCreateurs->contains($enginCreateur)) {
            $this->enginCreateurs->add($enginCreateur);
            $enginCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEnginCreateur(Engin $enginCreateur): static
    {
        if ($this->enginCreateurs->removeElement($enginCreateur)) {
            // set the owning side to null (unless already changed)
            if ($enginCreateur->getCreateur() === $this) {
                $enginCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, UtilisateurEntite>
     */
    public function getUtilisateurEntiteCreateurs(): Collection
    {
        return $this->utilisateurEntiteCreateurs;
    }

    public function addUtilisateurEntiteCreateur(UtilisateurEntite $utilisateurEntiteCreateur): static
    {
        if (!$this->utilisateurEntiteCreateurs->contains($utilisateurEntiteCreateur)) {
            $this->utilisateurEntiteCreateurs->add($utilisateurEntiteCreateur);
            $utilisateurEntiteCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeUtilisateurEntiteCreateur(UtilisateurEntite $utilisateurEntiteCreateur): static
    {
        if ($this->utilisateurEntiteCreateurs->removeElement($utilisateurEntiteCreateur)) {
            // set the owning side to null (unless already changed)
            if ($utilisateurEntiteCreateur->getCreateur() === $this) {
                $utilisateurEntiteCreateur->setCreateur(null);
            }
        }

        return $this;
    }





    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }
        return false;
    }

    public function needsOnboarding(): bool
    {
        // si l'utilisateur a déjà créé une entité => onboarding terminé
        if ($this->entites && $this->entites->count() > 0) {
            return false;
        }

        // sinon onboarding oui (il doit créer son premier organisme)
        return true;
    }

    /**
     * @return Collection<int, TransactionCarteAlx>
     */
    public function getTransactionCarteAlxes(): Collection
    {
        return $this->transactionCarteAlxes;
    }

    public function addTransactionCarteAlx(TransactionCarteAlx $transactionCarteAlx): static
    {
        if (!$this->transactionCarteAlxes->contains($transactionCarteAlx)) {
            $this->transactionCarteAlxes->add($transactionCarteAlx);
            $transactionCarteAlx->setUtilisateur($this);
        }

        return $this;
    }

    public function removeTransactionCarteAlx(TransactionCarteAlx $transactionCarteAlx): static
    {
        if ($this->transactionCarteAlxes->removeElement($transactionCarteAlx)) {
            // set the owning side to null (unless already changed)
            if ($transactionCarteAlx->getUtilisateur() === $this) {
                $transactionCarteAlx->setUtilisateur(null);
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
            $transactionCarteEdenred->setUtilisateur($this);
        }

        return $this;
    }

    public function removeTransactionCarteEdenred(TransactionCarteEdenred $transactionCarteEdenred): static
    {
        if ($this->transactionCarteEdenreds->removeElement($transactionCarteEdenred)) {
            // set the owning side to null (unless already changed)
            if ($transactionCarteEdenred->getUtilisateur() === $this) {
                $transactionCarteEdenred->setUtilisateur(null);
            }
        }

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
            $transactionCarteTotal->setUtilisateur($this);
        }

        return $this;
    }

    public function removeTransactionCarteTotal(TransactionCarteTotal $transactionCarteTotal): static
    {
        if ($this->transactionCarteTotals->removeElement($transactionCarteTotal)) {
            // set the owning side to null (unless already changed)
            if ($transactionCarteTotal->getUtilisateur() === $this) {
                $transactionCarteTotal->setUtilisateur(null);
            }
        }

        return $this;
    }


    public function getExternalIds(): Collection
    {
        return $this->externalIds;
    }

    public function addExternalId(UtilisateurExternalId $id): self
    {
        if (!$this->externalIds->contains($id)) {
            $this->externalIds->add($id);
            $id->setUtilisateur($this);
        }
        return $this;
    }

    public function removeExternalId(UtilisateurExternalId $id): self
    {
        if ($this->externalIds->removeElement($id)) {
            if ($id->getUtilisateur() === $this) {
                $id->setUtilisateur(null);
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
            $note->setUtilisateur($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getUtilisateur() === $this) {
                $note->setUtilisateur(null);
            }
        }

        return $this;
    }
}
