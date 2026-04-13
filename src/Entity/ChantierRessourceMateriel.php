<?php

namespace App\Entity;

use App\Repository\ChantierRessourceMaterielRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChantierRessourceMaterielRepository::class)]
class ChantierRessourceMateriel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ressourcesMateriels')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Chantier $chantier = null;

    #[ORM\ManyToOne(inversedBy: 'chantierRessources')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Materiel $materiel = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantite = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

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

    public function getMateriel(): ?Materiel
    {
        return $this->materiel;
    }
    public function setMateriel(?Materiel $materiel): static
    {
        $this->materiel = $materiel;
        return $this;
    }

    public function getQuantite(): ?int
    {
        return $this->quantite;
    }
    public function setQuantite(?int $quantite): static
    {
        $this->quantite = $quantite;
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
}
