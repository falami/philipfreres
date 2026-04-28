<?php

namespace App\Entity;

use App\Repository\ChantierRessourceEnginRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChantierRessourceEnginRepository::class)]
class ChantierRessourceEngin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ressourcesEngins')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ChantierZone $zone = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Engin $engin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

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

    public function getEngin(): ?Engin
    {
        return $this->engin;
    }

    public function setEngin(?Engin $engin): static
    {
        $this->engin = $engin;
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
