<?php

namespace App\Entity;

use App\Repository\ChantierRessourceHumaineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChantierRessourceHumaineRepository::class)]
class ChantierRessourceHumaine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ressourcesHumaines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ChantierZone $zone = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $fonction = null;

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

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;
        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = $fonction;
        return $this;
    }
}
