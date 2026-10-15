<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\PublicSecurityUrl;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\{RequestEvent, ResponseEvent};

final class ProductionHttpsSubscriber implements EventSubscriberInterface
{
    public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment) {}

    public static function getSubscribedEvents(): array
    {
        return [RequestEvent::class => ['enforceHttps', 128], ResponseEvent::class => ['protectTransport', -20]];
    }

    public function enforceHttps(RequestEvent $event): void
    {
        if ($this->environment !== 'prod' || !$event->isMainRequest() || $event->getRequest()->isSecure()) return;
        // A constant origin prevents forwarding credentials to an attacker-controlled Host.
        $event->setResponse(new RedirectResponse(PublicSecurityUrl::ORIGIN . $event->getRequest()->getRequestUri(), 308));
    }

    public function protectTransport(ResponseEvent $event): void
    {
        if ($this->environment === 'prod' && $event->isMainRequest() && $event->getRequest()->isSecure()) {
            $event->getResponse()->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
    }
}
