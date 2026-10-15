<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\{ControllerEvent, ResponseEvent};
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Routing\RouterInterface;

final class LoginProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TokenStorageInterface $tokens, private readonly RouterInterface $router) {}

    public static function getSubscribedEvents(): array
    {
        return [ControllerEvent::class => ['guardPartialLogin', 100], ResponseEvent::class => ['protectResponse', -10]];
    }

    public function guardPartialLogin(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $token = $this->tokens->getToken();
        if (!$token instanceof TwoFactorTokenInterface) return;
        $route = preg_replace('/\.(fr|en)$/', '', (string) $event->getRequest()->attributes->get('_route'));
        if (in_array($route, ['2fa_login', '2fa_login_check', 'app_2fa_resend', 'app_logout'], true)) return;
        $url = $this->router->generate('2fa_login');
        $event->setController(static fn() => new RedirectResponse($url));
    }

    public function protectResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        $route = preg_replace('/\.(fr|en)$/', '', (string) $request->attributes->get('_route'));
        $isAuth = in_array($route, ['app_login', 'app_logout', '2fa_login', '2fa_login_check', 'app_2fa_resend', 'app_account_security', 'app_account_sessions_revoke', 'app_employe_access_denied', 'app_forgot_password_request', 'app_reset_password', 'app_check_email'], true);
        if (!$isAuth) return;
        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (in_array($route, ['app_login', '2fa_login', 'app_account_security', 'app_employe_access_denied', 'app_forgot_password_request', 'app_reset_password', 'app_check_email'], true)) {
            $headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
        }
    }
}
