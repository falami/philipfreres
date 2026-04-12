<?php

namespace App\Entity;

use App\Enum\CategorieProduit;
use App\Enum\SousCategorieProduit;
use App\Entity\ProduitExternalId;
use App\Repository\ProduitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProduitRepository::class)]
#[ORM\Table(name: 'produit')]
class Produit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: CategorieProduit::class)]
    private CategorieProduit $categorieProduit = CategorieProduit::CARBURANT;

    #[ORM\Column(enumType: SousCategorieProduit::class)]
    private SousCategorieProduit $sousCategorieProduit = SousCategorieProduit::GASOIL;

    /**
     * @var Collection<int, ProduitExternalId>
     */
    #[ORM\OneToMany(
        targetEntity: ProduitExternalId::class,
        mappedBy: 'produit',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $externalIds;

    #[ORM\ManyToOne(inversedBy: 'produits')]
    private ?Entite $entite = null;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'produit')]
    private Collection $notes;

    public function __construct()
    {
        $this->externalIds = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategorieProduit(): CategorieProduit
    {
        return $this->categorieProduit;
    }

    public function setCategorieProduit(CategorieProduit $categorieProduit): static
    {
        $this->categorieProduit = $categorieProduit;
        return $this;
    }

    public function getSousCategorieProduit(): SousCategorieProduit
    {
        return $this->sousCategorieProduit;
    }

    public function setSousCategorieProduit(SousCategorieProduit $sousCategorieProduit): static
    {
        $this->sousCategorieProduit = $sousCategorieProduit;
        return $this;
    }

    /**
     * @return Collection<int, ProduitExternalId>
     */
    public function getExternalIds(): Collection
    {
        return $this->externalIds;
    }

    public function addExternalId(ProduitExternalId $id): self
    {
        if (!$this->externalIds->contains($id)) {
            $this->externalIds->add($id);
            $id->setProduit($this);
        }
        return $this;
    }

    public function removeExternalId(ProduitExternalId $id): self
    {
        if ($this->externalIds->removeElement($id)) {
            if ($id->getProduit() === $this) {
                $id->setProduit(null);
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
            $note->setProduit($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getProduit() === $this) {
                $note->setProduit(null);
            }
        }

        return $this;
    }
}
