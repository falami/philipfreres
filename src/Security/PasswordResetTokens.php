<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;

final class PasswordResetTokens
{
    public static function issue(Utilisateur $user, string $lifetime = '+1 hour'): string
    {
        $token = bin2hex(random_bytes(32));
        $user->setResetToken(self::digest($token));
        $user->setResetTokenExpiresAt(new \DateTimeImmutable($lifetime));
        return $token;
    }

    public static function digest(#[\SensitiveParameter] string $token): string
    {
        return 'sha256:' . hash('sha256', $token);
    }
}
