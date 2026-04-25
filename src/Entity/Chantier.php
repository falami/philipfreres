<?php

namespace App\Entity;

use App\Enum\ChantierStatut;
use App\Repository\ChantierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ChantierRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Chantier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'chantiers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $naturePrestation = null;

    #[ORM\Column(enumType: ChantierStatut::class)]
    private ChantierStatut $statut = ChantierStatut::BROUILLON;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateDebutPrevisionnelle = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateFinPrevisionnelle = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateDebutReelle = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateFinReelle = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $surfaceTraitee = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $lineaireTraite = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $difficultesRencontrees = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, ChantierZone>
     */
    #[ORM\OneToMany(mappedBy: 'chantier', targetEntity: ChantierZone::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC', 'id' => 'ASC'])]
    private Collection $zones;

    /**
     * @var Collection<int, ChantierRessourceHumaine>
     */
    #[ORM\OneToMany(mappedBy: 'chantier', targetEntity: ChantierRessourceHumaine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ressourcesHumaines;

    /**
     * @var Collection<int, ChantierRessourceEngin>
     */
    #[ORM\OneToMany(mappedBy: 'chantier', targetEntity: ChantierRessourceEngin::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ressourcesEngins;

    /**
     * @var Collection<int, ChantierRessourceMateriel>
     */
    #[ORM\OneToMany(mappedBy: 'chantier', targetEntity: ChantierRessourceMateriel::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ressourcesMateriels;

    /**
     * @var Collection<int, ChantierDechet>
     */
    #[ORM\OneToMany(mappedBy: 'chantier', targetEntity: ChantierDechet::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $dechets;

    /**
     * @var Collection<int, ChantierPhoto>
     */
    #[ORM\OneToMany(mappedBy: 'chantier', targetEntity: ChantierPhoto::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->zones = new ArrayCollection();
        $this->ressourcesHumaines = new ArrayCollection();
        $this->ressourcesEngins = new ArrayCollection();
        $this->ressourcesMateriels = new ArrayCollection();
        $this->dechets = new ArrayCollection();
        $this->photos = new ArrayCollection();
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
    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);
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

    public function getNaturePrestation(): ?string
    {
        return $this->naturePrestation;
    }
    public function setNaturePrestation(?string $naturePrestation): static
    {
        $this->naturePrestation = $naturePrestation;
        return $this;
    }

    public function getStatut(): ChantierStatut
    {
        return $this->statut;
    }
    public function setStatut(ChantierStatut $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getDateDebutPrevisionnelle(): ?\DateTimeInterface
    {
        return $this->dateDebutPrevisionnelle;
    }
    public function setDateDebutPrevisionnelle(?\DateTimeInterface $date): static
    {
        $this->dateDebutPrevisionnelle = $date;
        return $this;
    }

    public function getDateFinPrevisionnelle(): ?\DateTimeInterface
    {
        return $this->dateFinPrevisionnelle;
    }
    public function setDateFinPrevisionnelle(?\DateTimeInterface $date): static
    {
        $this->dateFinPrevisionnelle = $date;
        return $this;
    }

    public function getDateDebutReelle(): ?\DateTimeInterface
    {
        return $this->dateDebutReelle;
    }
    public function setDateDebutReelle(?\DateTimeInterface $date): static
    {
        $this->dateDebutReelle = $date;
        return $this;
    }

    public function getDateFinReelle(): ?\DateTimeInterface
    {
        return $this->dateFinReelle;
    }
    public function setDateFinReelle(?\DateTimeInterface $date): static
    {
        $this->dateFinReelle = $date;
        return $this;
    }

    public function getSurfaceTraitee(): ?string
    {
        return $this->surfaceTraitee;
    }
    public function setSurfaceTraitee(?string $surfaceTraitee): static
    {
        $this->surfaceTraitee = $surfaceTraitee;
        return $this;
    }

    public function getLineaireTraite(): ?string
    {
        return $this->lineaireTraite;
    }
    public function setLineaireTraite(?string $lineaireTraite): static
    {
        $this->lineaireTraite = $lineaireTraite;
        return $this;
    }

    public function getDifficultesRencontrees(): ?string
    {
        return $this->difficultesRencontrees;
    }
    public function setDifficultesRencontrees(?string $texte): static
    {
        $this->difficultesRencontrees = $texte;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getSemainePrevisionnelle(): ?string
    {
        if (!$this->dateDebutPrevisionnelle) {
            return null;
        }

        return sprintf(
            'S%02d - %s',
            (int) $this->dateDebutPrevisionnelle->format('W'),
            $this->dateDebutPrevisionnelle->format('Y')
        );
    }

    public function getDureePrevisionnelleJours(): ?int
    {
        if (!$this->dateDebutPrevisionnelle || !$this->dateFinPrevisionnelle) {
            return null;
        }

        return ((int) $this->dateDebutPrevisionnelle->diff($this->dateFinPrevisionnelle)->days) + 1;
    }

    public function getDureeReelleJours(): ?int
    {
        if (!$this->dateDebutReelle || !$this->dateFinReelle) {
            return null;
        }

        return ((int) $this->dateDebutReelle->diff($this->dateFinReelle)->days) + 1;
    }

    /** @return Collection<int, ChantierZone> */
    public function getZones(): Collection
    {
        return $this->zones;
    }
    public function addZone(ChantierZone $zone): static
    {
        if (!$this->zones->contains($zone)) {
            $this->zones->add($zone);
            $zone->setChantier($this);
        }
        return $this;
    }
    public function removeZone(ChantierZone $zone): static
    {
        if ($this->zones->removeElement($zone) && $zone->getChantier() === $this) {
            $zone->setChantier(null);
        }
        return $this;
    }

    /** @return Collection<int, ChantierRessourceHumaine> */
    public function getRessourcesHumaines(): Collection
    {
        return $this->ressourcesHumaines;
    }
    public function addRessourceHumaine(ChantierRessourceHumaine $item): static
    {
        if (!$this->ressourcesHumaines->contains($item)) {
            $this->ressourcesHumaines->add($item);
            $item->setChantier($this);
        }
        return $this;
    }
    public function removeRessourceHumaine(ChantierRessourceHumaine $item): static
    {
        if ($this->ressourcesHumaines->removeElement($item) && $item->getChantier() === $this) {
            $item->setChantier(null);
        }
        return $this;
    }

    /** @return Collection<int, ChantierRessourceEngin> */
    public function getRessourcesEngins(): Collection
    {
        return $this->ressourcesEngins;
    }
    public function addRessourceEngin(ChantierRessourceEngin $item): static
    {
        if (!$this->ressourcesEngins->contains($item)) {
            $this->ressourcesEngins->add($item);
            $item->setChantier($this);
        }
        return $this;
    }
    public function removeRessourceEngin(ChantierRessourceEngin $item): static
    {
        if ($this->ressourcesEngins->removeElement($item) && $item->getChantier() === $this) {
            $item->setChantier(null);
        }
        return $this;
    }

    /** @return Collection<int, ChantierRessourceMateriel> */
    public function getRessourcesMateriels(): Collection
    {
        return $this->ressourcesMateriels;
    }
    public function addRessourceMateriel(ChantierRessourceMateriel $item): static
    {
        if (!$this->ressourcesMateriels->contains($item)) {
            $this->ressourcesMateriels->add($item);
            $item->setChantier($this);
        }
        return $this;
    }
    public function removeRessourceMateriel(ChantierRessourceMateriel $item): static
    {
        if ($this->ressourcesMateriels->removeElement($item) && $item->getChantier() === $this) {
            $item->setChantier(null);
        }
        return $this;
    }

    /** @return Collection<int, ChantierDechet> */
    public function getDechets(): Collection
    {
        return $this->dechets;
    }
    public function addDechet(ChantierDechet $item): static
    {
        if (!$this->dechets->contains($item)) {
            $this->dechets->add($item);
            $item->setChantier($this);
        }
        return $this;
    }
    public function removeDechet(ChantierDechet $item): static
    {
        if ($this->dechets->removeElement($item) && $item->getChantier() === $this) {
            $item->setChantier(null);
        }
        return $this;
    }

    /** @return Collection<int, ChantierPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }
    public function addPhoto(ChantierPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setChantier($this);
        }
        return $this;
    }
    public function removePhoto(ChantierPhoto $photo): static
    {
        if ($this->photos->removeElement($photo) && $photo->getChantier() === $this) {
            $photo->setChantier(null);
        }
        return $this;
    }

    // Alias nécessaires pour Symfony Form / PropertyAccess

    public function addRessourcesHumaine(ChantierRessourceHumaine $item): static
    {
        return $this->addRessourceHumaine($item);
    }

    public function removeRessourcesHumaine(ChantierRessourceHumaine $item): static
    {
        return $this->removeRessourceHumaine($item);
    }

    public function addRessourcesEngin(ChantierRessourceEngin $item): static
    {
        return $this->addRessourceEngin($item);
    }

    public function removeRessourcesEngin(ChantierRessourceEngin $item): static
    {
        return $this->removeRessourceEngin($item);
    }

    public function addRessourcesMateriel(ChantierRessourceMateriel $item): static
    {
        return $this->addRessourceMateriel($item);
    }

    public function removeRessourcesMateriel(ChantierRessourceMateriel $item): static
    {
        return $this->removeRessourceMateriel($item);
    }
}
