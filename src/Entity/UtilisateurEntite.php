<?php

namespace App\Entity;

use App\Repository\UtilisateurEntiteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UtilisateurEntiteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_user_entite', columns: ['utilisateur_id', 'entite_id'])]
class UtilisateurEntite
{
    // ✅ Rôles tenant (JSON)
    public const TENANT_EMPLOYE     = 'TENANT_EMPLOYE';
    public const TENANT_ADMIN       = 'TENANT_ADMIN';

    // ✅ Status
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_INVITED   = 'invited';
    public const STATUS_SUSPENDED = 'suspended';

    // ✅ Ranks legacy (si ton code compare encore des int)
    public const ROLE_EMPLOYE  = 1;
    public const ROLE_ADMIN      = 2;
    public const ROLE_SUPER      = 3;

    private const COLOR_POOL = [
        '#0d6efd',
        '#198754',
        '#ffc107',
        '#dc3545',
        '#0dcaf0',
        '#6f42c1',
        '#fd7e14',
        '#20c997',
        '#6610f2',
        '#6c757d',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'utilisateurEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(inversedBy: 'utilisateurEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fonction = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'utilisateurEntiteCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [self::TENANT_EMPLOYE];

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    public function __construct()
    {
        // couleur aléatoire uniquement pour les nouveaux objets
        $this->couleur ??= self::COLOR_POOL[array_rand(self::COLOR_POOL)];
        $this->dateCreation = new \DateTimeImmutable();

        // sécurité : jamais roles vide
        $this->roles = $this->normalizeRoles($this->roles);
        if ($this->roles === []) {
            $this->roles = [self::TENANT_EMPLOYE];
        }
    }

    public function ensureCouleur(): void
    {
        if (!$this->couleur) {
            $this->couleur = self::COLOR_POOL[array_rand(self::COLOR_POOL)];
        }
    }

    // -----------------------
    // Getters / setters
    // -----------------------

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }
    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;
        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }
    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;
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

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }
    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
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

    // -----------------------
    // Roles JSON (robuste)
    // -----------------------

    public function getRoles(): array
    {
        $roles = $this->normalizeRoles($this->roles);
        return $roles ?: [self::TENANT_EMPLOYE];
    }

    public function setRole(int $rank): self
    {
        // Legacy : on AJOUTE un rôle "principal", sans enlever les autres
        $primary = match (true) {
            $rank >= self::ROLE_ADMIN      => self::TENANT_ADMIN,
            default                        => self::TENANT_EMPLOYE,
        };

        return $this->addRole($primary);
    }

    public function addRole(string $role): self
    {
        $role = trim((string) $role);
        if ($role === '') return $this;

        $roles = $this->getRoles();
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
            $this->roles = $this->normalizeRoles($roles);
        }
        return $this;
    }

    public function removeRole(string $role): self
    {
        $role = trim((string) $role);
        if ($role === '') return $this;

        $roles = array_values(array_filter(
            $this->getRoles(),
            static fn(string $r) => $r !== $role
        ));

        $this->roles = $roles ?: [self::TENANT_EMPLOYE];
        return $this;
    }

    public function hasRole(string $role): bool
    {
        $role = trim((string) $role);
        if ($role === '') return false;
        return in_array($role, $this->getRoles(), true);
    }

    private function normalizeRoles(array $roles): array
    {
        $roles = array_map(static fn($r) => trim((string) $r), $roles);
        $roles = array_values(array_filter($roles, static fn(string $r) => $r !== ''));
        return array_values(array_unique($roles));
    }

    // -----------------------
    // Status
    // -----------------------

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $status = trim($status);
        if ($status === '') {
            $status = self::STATUS_ACTIVE;
        }

        // Optionnel : valider strictement
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_INVITED, self::STATUS_SUSPENDED], true)) {
            $status = self::STATUS_ACTIVE;
        }

        $this->status = $status;
        return $this;
    }

    // -----------------------
    // Admin tenant
    // -----------------------

    public function isTenantAdmin(): bool
    {
        return $this->hasRole(self::TENANT_ADMIN);
    }

    // ==========================================================
    // ✅ BRIDGE LEGACY: getRole()/setRole() pour ne pas casser ton code
    // ==========================================================

    public function getRole(): int
    {
        return $this->getRoleRank();
    }

    public function getRoleRank(): int
    {
        $roles = $this->getRoles();

        if (in_array(self::TENANT_ADMIN, $roles, true)) {
            return self::ROLE_ADMIN;
        }
        return self::TENANT_EMPLOYE;
    }

    public function setRoles(array $roles): self
    {
        $roles = $this->normalizeRoles($roles);
        $this->roles = $roles ?: [self::TENANT_EMPLOYE];
        return $this;
    }


    public static function tenantRoleLabels(): array
    {
        return [
            self::TENANT_EMPLOYE    => 'Employe',
            self::TENANT_ADMIN      => 'Administrateur',
        ];
    }

    public function getRolesLabels(): array
    {
        $map = self::tenantRoleLabels();
        return array_map(fn(string $r) => $map[$r] ?? $r, $this->getRoles());
    }
}
