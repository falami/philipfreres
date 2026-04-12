<?php

namespace App\Entity;

use App\Repository\EnginRepository;
use App\Enum\EnginType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EnginRepository::class)]
class Engin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;


    #[ORM\Column(length: 140)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(enumType: EnginType::class)]
    private EnginType $type = EnginType::CHARGEUSE;


    #[ORM\Column(nullable: true)]
    private ?int $annee = 2025;


    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoCouverture = null;


    #[ORM\ManyToOne(inversedBy: 'engins')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'enginCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $immatriculation = null; // plaque “réelle”

    /**
     * @var Collection<int, TransactionCarteAlx>
     */
    #[ORM\OneToMany(targetEntity: TransactionCarteAlx::class, mappedBy: 'engin')]
    private Collection $transactionCarteAlxes;

    /**
     * @var Collection<int, TransactionCarteEdenred>
     */
    #[ORM\OneToMany(targetEntity: TransactionCarteEdenred::class, mappedBy: 'engin')]
    private Collection $transactionCarteEdenreds;

    /**
     * @var Collection<int, TransactionCarteTotal>
     */
    #[ORM\OneToMany(targetEntity: TransactionCarteTotal::class, mappedBy: 'engin')]
    private Collection $transactionCarteTotals;


    #[ORM\OneToMany(
        targetEntity: EnginExternalId::class,
        mappedBy: 'engin',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $externalIds;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'engin')]
    private Collection $notes;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
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

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getType(): EnginType
    {
        return $this->type;
    }

    public function setType(EnginType $type): static
    {
        $this->type = $type;
        return $this;
    }


    public function getPhotoCouverture(): ?string
    {
        return $this->photoCouverture;
    }

    public function setPhotoCouverture(?string $photoCouverture): static
    {
        $this->photoCouverture = $photoCouverture;

        return $this;
    }

    public function getAnnee(): ?int
    {
        return $this->annee;
    }
    public function setAnnee(?int $annee): static
    {
        $this->annee = $annee;
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

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

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

    public function getImmatriculation(): ?string
    {
        return $this->immatriculation;
    }

    public function setImmatriculation(?string $immatriculation): static
    {
        if ($immatriculation === null) {
            $this->immatriculation = null;
            return $this;
        }

        $s = strtoupper(trim($immatriculation));
        $s = preg_replace('/\s+/', '', $s) ?: $s;
        $s = preg_replace('/[^A-Z0-9\-]/', '', $s) ?: $s;

        $this->immatriculation = ($s !== '') ? $s : null;
        return $this;
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
            $transactionCarteAlx->setEngin($this);
        }

        return $this;
    }

    public function removeTransactionCarteAlx(TransactionCarteAlx $transactionCarteAlx): static
    {
        if ($this->transactionCarteAlxes->removeElement($transactionCarteAlx)) {
            // set the owning side to null (unless already changed)
            if ($transactionCarteAlx->getEngin() === $this) {
                $transactionCarteAlx->setEngin(null);
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
            $transactionCarteEdenred->setEngin($this);
        }

        return $this;
    }

    public function removeTransactionCarteEdenred(TransactionCarteEdenred $transactionCarteEdenred): static
    {
        if ($this->transactionCarteEdenreds->removeElement($transactionCarteEdenred)) {
            // set the owning side to null (unless already changed)
            if ($transactionCarteEdenred->getEngin() === $this) {
                $transactionCarteEdenred->setEngin(null);
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
            $transactionCarteTotal->setEngin($this);
        }

        return $this;
    }

    public function removeTransactionCarteTotal(TransactionCarteTotal $transactionCarteTotal): static
    {
        if ($this->transactionCarteTotals->removeElement($transactionCarteTotal)) {
            // set the owning side to null (unless already changed)
            if ($transactionCarteTotal->getEngin() === $this) {
                $transactionCarteTotal->setEngin(null);
            }
        }

        return $this;
    }

    public function getExternalIds(): Collection
    {
        return $this->externalIds;
    }

    public function addExternalId(EnginExternalId $id): self
    {
        if (!$this->externalIds->contains($id)) {
            $this->externalIds->add($id);
            $id->setEngin($this);
        }
        return $this;
    }

    public function removeExternalId(EnginExternalId $id): self
    {
        if ($this->externalIds->removeElement($id)) {
            if ($id->getEngin() === $this) {
                $id->setEngin(null);
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
            $note->setEngin($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getEngin() === $this) {
                $note->setEngin(null);
            }
        }

        return $this;
    }
}
