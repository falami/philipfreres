<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccountChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        // Keep the same credential error for disabled and unknown accounts.
        if ($user instanceof Utilisateur && ($user->isBannir() || $user->isDesactiverTemporairement())) {
            throw new CustomUserMessageAccountStatusException('Invalid credentials.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        $this->checkPreAuth($user);
    }
}
