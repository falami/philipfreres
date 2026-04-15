<?php

namespace App\Entity;

use App\Enum\MaterielCategorie;
use App\Enum\MaterielStatut;
use App\Repository\MaterielRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MaterielRepository::class)]
class Materiel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 140)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 140)]
    private ?string $nom = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $type = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $numeroSerie = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $photoCouverture = null;

    #[ORM\Column(enumType: MaterielCategorie::class, nullable: true)]
    private ?MaterielCategorie $categorie = null;

    #[ORM\Column(enumType: MaterielStatut::class)]
    private MaterielStatut $statut = MaterielStatut::DISPONIBLE;

    #[ORM\ManyToOne(inversedBy: 'materiels')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    /**
     * @var Collection<int, ChantierRessourceMateriel>
     */
    #[ORM\OneToMany(mappedBy: 'materiel', targetEntity: ChantierRessourceMateriel::class, orphanRemoval: false)]
    private Collection $chantierRessources;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->chantierRessources = new ArrayCollection();
        $this->statut = MaterielStatut::DISPONIBLE;
    }

    public function __toString(): string
    {
        $categorie = $this->categorie?->label() ?? 'Sans catégorie';
        $nom = $this->nom ?? '';

        return trim($categorie . ' - ' . $nom, ' -');
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
        $this->nom = trim($nom);

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type !== null ? trim($type) : null;

        return $this;
    }

    public function getNumeroSerie(): ?string
    {
        return $this->numeroSerie;
    }

    public function setNumeroSerie(?string $numeroSerie): static
    {
        $this->numeroSerie = $numeroSerie !== null ? trim($numeroSerie) : null;

        return $this;
    }

    public function getPhotoCouverture(): ?string
    {
        return $this->photoCouverture;
    }

    public function setPhotoCouverture(?string $photoCouverture): static
    {
        $this->photoCouverture = $photoCouverture !== null ? trim($photoCouverture) : null;

        return $this;
    }

    public function getCategorie(): ?MaterielCategorie
    {
        return $this->categorie;
    }

    public function setCategorie(?MaterielCategorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getStatut(): MaterielStatut
    {
        return $this->statut;
    }

    public function setStatut(MaterielStatut $statut): static
    {
        $this->statut = $statut;

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

    /**
     * @return Collection<int, ChantierRessourceMateriel>
     */
    public function getChantierRessources(): Collection
    {
        return $this->chantierRessources;
    }

    public function addChantierRessource(ChantierRessourceMateriel $chantierRessource): static
    {
        if (!$this->chantierRessources->contains($chantierRessource)) {
            $this->chantierRessources->add($chantierRessource);
            $chantierRessource->setMateriel($this);
        }

        return $this;
    }

    public function removeChantierRessource(ChantierRessourceMateriel $chantierRessource): static
    {
        if ($this->chantierRessources->removeElement($chantierRessource)) {
            if ($chantierRessource->getMateriel() === $this) {
                $chantierRessource->setMateriel(null);
            }
        }

        return $this;
    }
}
