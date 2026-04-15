<?php

namespace App\Security\Voter;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class TenantVoter extends Voter
{
  public function __construct(
    private readonly UtilisateurEntiteRepository $ueRepo
  ) {}

  protected function supports(string $attribute, mixed $subject): bool
  {
    return $subject instanceof Entite
      && in_array($attribute, TenantPermission::ALL, true);
  }

  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
  {
    $user = $token->getUser();
    if (!$user instanceof Utilisateur) return false;

    // ✅ SUPER ADMIN => accès total sur toutes les entités
    if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
      return true;
    }

    /** @var Entite $entite */
    $entite = $subject;

    $membership = $this->ueRepo->findMembership($user, $entite);
    if (!$membership || !$membership->isActive()) return false;

    if ($attribute === TenantPermission::ACCESS) return true;

    $isAdmin = $membership->isTenantAdmin();
    if ($attribute === TenantPermission::ADMIN) return $isAdmin;

    $roleMap = [
      TenantPermission::EMPLOYE => UtilisateurEntite::TENANT_EMPLOYE,
    ];

    if (isset($roleMap[$attribute])) {
      return $membership->hasRole($roleMap[$attribute]);
    }

    return in_array($attribute, self::ADMIN_ONLY, true) ? $isAdmin : false;
  }



  private const ADMIN_ONLY = [
    TenantPermission::UTILISATEUR_MANAGE,
    TenantPermission::ENGIN_MANAGE,
    TenantPermission::ADMIN_DASHBOARD_MANAGE,
    TenantPermission::USERS_MANAGE,
    TenantPermission::CHANTIER_MANAGE,
    TenantPermission::MATERIEL_MANAGE,


  ];
}
