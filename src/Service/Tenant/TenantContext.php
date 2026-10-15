<?php

namespace App\Service\Tenant;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\EntiteRepository;
use App\Repository\UtilisateurEntiteRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TenantContext
{
    public const PLATFORM_ENTITE_ID = 1;

    public function __construct(
        private RequestStack $requestStack,
        private EntiteRepository $entiteRepo,
        private UtilisateurEntiteRepository $ueRepo,
    ) {}

    public function getCurrentEntiteForUser(Utilisateur $user): ?Entite
    {
        $session = $this->requestStack->getSession();
        $entiteId = (int) $session->get('current_entite_id', 0);

        if ($entiteId > 0) {
            $entite = $this->entiteRepo->find($entiteId);
            if ($entite && $this->hasActiveMembership($user, $entite)) {
                return $entite;
            }
            $session->remove('current_entite_id');
        }

        // fallback : entité stockée sur user si valide
        $entite = $user->getEntite();
        if ($entite && $this->hasActiveMembership($user, $entite)) {
            $session->set('current_entite_id', $entite->getId());
            return $entite;
        }

        // A suspended or invited membership never becomes the current context.
        foreach ($this->ueRepo->findAllForUser($user) as $membership) {
            $entite = $membership->getEntite();
            if ($membership->isActive() && $entite?->getId() !== null) {
                $session->set('current_entite_id', $entite->getId());
                return $entite;
            }
        }

        $session->remove('current_entite_id');
        return null;
    }

    public function setCurrentEntite(Utilisateur $user, Entite $entite): void
    {
        if (!$this->hasActiveMembership($user, $entite)) {
            throw new AccessDeniedException('Aucun accès actif à cette entité.');
        }

        $this->requestStack->getSession()->set('current_entite_id', $entite->getId());
    }

    private function hasActiveMembership(Utilisateur $user, Entite $entite): bool
    {
        return $entite->getId() !== null
            && ($this->ueRepo->findMembership($user, $entite)?->isActive() ?? false);
    }

    public function isPlatformEntite(?Entite $entite): bool
    {
        return $entite && $entite->getId() === self::PLATFORM_ENTITE_ID;
    }
}
