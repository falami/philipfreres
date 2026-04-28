<?php

namespace App\Entity;

use App\Repository\ChantierZoneRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ChantierZoneRepository::class)]
class ChantierZone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'zones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Chantier $chantier = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $parcelle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $naturePrestation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateDebutPrevisionnelle = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateFinPrevisionnelle = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateDebutReelle = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateFinReelle = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $surfaceTraitee = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $lineaireTraite = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $difficultesRencontrees = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\OneToMany(mappedBy: 'zone', targetEntity: ChantierRessourceHumaine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ressourcesHumaines;

    #[ORM\OneToMany(mappedBy: 'zone', targetEntity: ChantierRessourceEngin::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ressourcesEngins;

    #[ORM\OneToMany(mappedBy: 'zone', targetEntity: ChantierRessourceMateriel::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ressourcesMateriels;

    #[ORM\OneToMany(mappedBy: 'zone', targetEntity: ChantierDechet::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $dechets;

    #[ORM\OneToMany(mappedBy: 'zone', targetEntity: ChantierPhoto::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->ressourcesHumaines = new ArrayCollection();
        $this->ressourcesEngins = new ArrayCollection();
        $this->ressourcesMateriels = new ArrayCollection();
        $this->dechets = new ArrayCollection();
        $this->photos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChantier(): ?Chantier
    {
        return $this->chantier;
    }

    public function setChantier(?Chantier $chantier): static
    {
        $this->chantier = $chantier;
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

    public function getParcelle(): ?string
    {
        return $this->parcelle;
    }

    public function setParcelle(?string $parcelle): static
    {
        $this->parcelle = $parcelle;
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

    public function setDifficultesRencontrees(?string $difficultesRencontrees): static
    {
        $this->difficultesRencontrees = $difficultesRencontrees;
        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;
        return $this;
    }

    public function getDureePrevisionnelleHeures(): ?float
    {
        if (!$this->dateDebutPrevisionnelle || !$this->dateFinPrevisionnelle) {
            return null;
        }

        $seconds = $this->dateFinPrevisionnelle->getTimestamp() - $this->dateDebutPrevisionnelle->getTimestamp();

        return $seconds >= 0 ? round($seconds / 3600, 2) : null;
    }

    public function getDureeReelleHeures(): ?float
    {
        if (!$this->dateDebutReelle || !$this->dateFinReelle) {
            return null;
        }

        $seconds = $this->dateFinReelle->getTimestamp() - $this->dateDebutReelle->getTimestamp();

        return $seconds >= 0 ? round($seconds / 3600, 2) : null;
    }

    public function getRessourcesHumaines(): Collection
    {
        return $this->ressourcesHumaines;
    }

    public function addRessourceHumaine(ChantierRessourceHumaine $item): static
    {
        if (!$this->ressourcesHumaines->contains($item)) {
            $this->ressourcesHumaines->add($item);
            $item->setZone($this);
        }

        return $this;
    }

    public function removeRessourceHumaine(ChantierRessourceHumaine $item): static
    {
        if ($this->ressourcesHumaines->removeElement($item) && $item->getZone() === $this) {
            $item->setZone(null);
        }

        return $this;
    }

    public function getRessourcesEngins(): Collection
    {
        return $this->ressourcesEngins;
    }

    public function addRessourceEngin(ChantierRessourceEngin $item): static
    {
        if (!$this->ressourcesEngins->contains($item)) {
            $this->ressourcesEngins->add($item);
            $item->setZone($this);
        }

        return $this;
    }

    public function removeRessourceEngin(ChantierRessourceEngin $item): static
    {
        if ($this->ressourcesEngins->removeElement($item) && $item->getZone() === $this) {
            $item->setZone(null);
        }

        return $this;
    }

    public function getRessourcesMateriels(): Collection
    {
        return $this->ressourcesMateriels;
    }

    public function addRessourceMateriel(ChantierRessourceMateriel $item): static
    {
        if (!$this->ressourcesMateriels->contains($item)) {
            $this->ressourcesMateriels->add($item);
            $item->setZone($this);
        }

        return $this;
    }

    public function removeRessourceMateriel(ChantierRessourceMateriel $item): static
    {
        if ($this->ressourcesMateriels->removeElement($item) && $item->getZone() === $this) {
            $item->setZone(null);
        }

        return $this;
    }

    public function getDechets(): Collection
    {
        return $this->dechets;
    }

    public function addDechet(ChantierDechet $item): static
    {
        if (!$this->dechets->contains($item)) {
            $this->dechets->add($item);
            $item->setZone($this);
        }

        return $this;
    }

    public function removeDechet(ChantierDechet $item): static
    {
        if ($this->dechets->removeElement($item) && $item->getZone() === $this) {
            $item->setZone(null);
        }

        return $this;
    }

    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(ChantierPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setZone($this);
        }

        return $this;
    }

    public function removePhoto(ChantierPhoto $photo): static
    {
        if ($this->photos->removeElement($photo) && $photo->getZone() === $this) {
            $photo->setZone(null);
        }

        return $this;
    }

    // Alias utiles pour Symfony Form / PropertyAccess

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
