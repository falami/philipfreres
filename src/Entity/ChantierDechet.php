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
    private ?Chantier $chantier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?DechetType $typeDechet = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $poidsTotal = null;

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

    public function getTypeDechet(): ?DechetType
    {
        return $this->typeDechet;
    }
    public function setTypeDechet(?DechetType $typeDechet): static
    {
        $this->typeDechet = $typeDechet;
        return $this;
    }

    public function getPoidsTotal(): ?string
    {
        return $this->poidsTotal;
    }
    public function setPoidsTotal(?string $poidsTotal): static
    {
        $this->poidsTotal = $poidsTotal;
        return $this;
    }
}
