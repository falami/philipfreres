<?php

namespace App\Entity;

use App\Repository\ChantierDechetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChantierDechetRepository::class)]
class ChantierDechet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'dechets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ChantierZone $zone = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Dechet $typeDechet = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $quantite = null;

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

    public function getTypeDechet(): ?Dechet
    {
        return $this->typeDechet;
    }

    public function setTypeDechet(?Dechet $typeDechet): static
    {
        $this->typeDechet = $typeDechet;
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

    public function getPoidsTotal(): ?string
    {
        return $this->quantite;
    }

    public function setPoidsTotal(?string $poidsTotal): static
    {
        $this->quantite = $poidsTotal;
        return $this;
    }
}
