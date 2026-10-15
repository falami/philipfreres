<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Account emails must never derive their destination host from an inbound Host header. */
final class PublicSecurityUrl
{
    public const ORIGIN = 'https://app.philipfreres.com';

    public function __construct(private readonly UrlGeneratorInterface $router) {}

    public function reset(#[\SensitiveParameter] string $token): string
    {
        return self::ORIGIN . $this->router->generate('app_reset_password', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
