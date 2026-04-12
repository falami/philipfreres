<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\GeoAddressCacheRepository::class)]
#[ORM\Table(name: 'geo_address_cache')]
#[ORM\UniqueConstraint(name: 'uniq_geo_entite_hash', columns: ['entite_id', 'addr_hash'])]
#[ORM\Index(name: 'idx_geo_entite', columns: ['entite_id'])]
class GeoAddressCache
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Entite $entite = null;

  #[ORM\Column(length: 64)]
  private string $addrHash;

  #[ORM\Column(length: 500)]
  private string $address;

  #[ORM\Column(nullable: true)]
  private ?float $lat = null;

  #[ORM\Column(nullable: true)]
  private ?float $lng = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $geocodedAt = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $provider = null; // ex: "nominatim"

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $displayName = null;

  #[ORM\Column(nullable: true)]
  private ?int $confidence = null;

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getEntite(): ?Entite
  {
    return $this->entite;
  }
  public function setEntite(Entite $e): self
  {
    $this->entite = $e;
    return $this;
  }

  public function getAddrHash(): string
  {
    return $this->addrHash;
  }
  public function setAddrHash(string $h): self
  {
    $this->addrHash = $h;
    return $this;
  }

  public function getAddress(): string
  {
    return $this->address;
  }
  public function setAddress(string $a): self
  {
    $this->address = $a;
    return $this;
  }

  public function getLat(): ?float
  {
    return $this->lat;
  }
  public function setLat(?float $v): self
  {
    $this->lat = $v;
    return $this;
  }

  public function getLng(): ?float
  {
    return $this->lng;
  }
  public function setLng(?float $v): self
  {
    $this->lng = $v;
    return $this;
  }

  public function getGeocodedAt(): ?\DateTimeImmutable
  {
    return $this->geocodedAt;
  }
  public function setGeocodedAt(?\DateTimeImmutable $d): self
  {
    $this->geocodedAt = $d;
    return $this;
  }

  public function getProvider(): ?string
  {
    return $this->provider;
  }
  public function setProvider(?string $p): self
  {
    $this->provider = $p;
    return $this;
  }

  public function getDisplayName(): ?string
  {
    return $this->displayName;
  }
  public function setDisplayName(?string $s): self
  {
    $this->displayName = $s;
    return $this;
  }

  public function getConfidence(): ?int
  {
    return $this->confidence;
  }
  public function setConfidence(?int $c): self
  {
    $this->confidence = $c;
    return $this;
  }
}
