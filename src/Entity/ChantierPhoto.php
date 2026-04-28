<?php

namespace App\Entity;

use App\Repository\ChantierPhotoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChantierPhotoRepository::class)]
class ChantierPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ChantierZone $zone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $titre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoAvant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoApres = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresseAvant = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $latitudeAvant = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $longitudeAvant = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sourceLocalisationAvant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresseApres = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $latitudeApres = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $longitudeApres = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sourceLocalisationApres = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $datePriseVueAvant = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $datePriseVueApres = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getZone(): ?ChantierZone
    {
        return $this->zone;
    }

    public function setZone(?ChantierZone $zone): static
    {
        $this->zone = $zone;
        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;
        return $this;
    }

    public function getPhotoAvant(): ?string
    {
        return $this->photoAvant;
    }

    public function setPhotoAvant(?string $photoAvant): static
    {
        $this->photoAvant = $photoAvant;
        return $this;
    }

    public function getPhotoApres(): ?string
    {
        return $this->photoApres;
    }

    public function setPhotoApres(?string $photoApres): static
    {
        $this->photoApres = $photoApres;
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

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;
        return $this;
    }

    public function getAdresseAvant(): ?string
    {
        return $this->adresseAvant;
    }

    public function setAdresseAvant(?string $adresseAvant): static
    {
        $this->adresseAvant = $adresseAvant;
        return $this;
    }

    public function getLatitudeAvant(): ?string
    {
        return $this->latitudeAvant;
    }

    public function setLatitudeAvant(?string $latitudeAvant): static
    {
        $this->latitudeAvant = $latitudeAvant;
        return $this;
    }

    public function getLongitudeAvant(): ?string
    {
        return $this->longitudeAvant;
    }

    public function setLongitudeAvant(?string $longitudeAvant): static
    {
        $this->longitudeAvant = $longitudeAvant;
        return $this;
    }

    public function getSourceLocalisationAvant(): ?string
    {
        return $this->sourceLocalisationAvant;
    }

    public function setSourceLocalisationAvant(?string $sourceLocalisationAvant): static
    {
        $this->sourceLocalisationAvant = $sourceLocalisationAvant;
        return $this;
    }

    public function getAdresseApres(): ?string
    {
        return $this->adresseApres;
    }

    public function setAdresseApres(?string $adresseApres): static
    {
        $this->adresseApres = $adresseApres;
        return $this;
    }

    public function getLatitudeApres(): ?string
    {
        return $this->latitudeApres;
    }

    public function setLatitudeApres(?string $latitudeApres): static
    {
        $this->latitudeApres = $latitudeApres;
        return $this;
    }

    public function getLongitudeApres(): ?string
    {
        return $this->longitudeApres;
    }

    public function setLongitudeApres(?string $longitudeApres): static
    {
        $this->longitudeApres = $longitudeApres;
        return $this;
    }

    public function getSourceLocalisationApres(): ?string
    {
        return $this->sourceLocalisationApres;
    }

    public function setSourceLocalisationApres(?string $sourceLocalisationApres): static
    {
        $this->sourceLocalisationApres = $sourceLocalisationApres;
        return $this;
    }

    public function getDatePriseVueAvant(): ?\DateTimeInterface
    {
        return $this->datePriseVueAvant;
    }

    public function setDatePriseVueAvant(?\DateTimeInterface $datePriseVueAvant): static
    {
        $this->datePriseVueAvant = $datePriseVueAvant;
        return $this;
    }

    public function getDatePriseVueApres(): ?\DateTimeInterface
    {
        return $this->datePriseVueApres;
    }

    public function setDatePriseVueApres(?\DateTimeInterface $datePriseVueApres): static
    {
        $this->datePriseVueApres = $datePriseVueApres;
        return $this;
    }
}
