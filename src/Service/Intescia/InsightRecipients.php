<?php

declare(strict_types=1);
namespace App\Service\Intescia;

use App\Repository\UtilisateurEntiteRepository;

class InsightRecipients
{
    public function __construct(private readonly UtilisateurEntiteRepository $memberships) {}

    public function available(int $tenant): array
    {
        $members = $this->memberships->createQueryBuilder('m')->join('m.utilisateur', 'u')->addSelect('u')
            ->where('m.entite = :tenant')->andWhere('m.status = :status')->setParameter('tenant', $tenant)->setParameter('status', 'active')
            ->orderBy('u.nom', 'ASC')->getQuery()->getResult();
        $users = [];
        foreach ($members as $member) {
            $user = $member->getUtilisateur();
            if (!$user || $user->isBannir() || $user->isDesactiverTemporairement() || !filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL)) { continue; }
            $users[$user->getId()] = ['id' => $user->getId(), 'name' => trim($user->getPrenom().' '.$user->getNom()), 'email' => $user->getEmail()];
        }
        return $users;
    }
}
