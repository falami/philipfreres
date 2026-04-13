<?php

namespace App\Entity;

use App\Repository\ChantierZoneRepository;
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

    #[ORM\Column]
    private int $ordre = 0;

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

    public function getOrdre(): int
    {
        return $this->ordre;
    }
    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;
        return $this;
    }
}
