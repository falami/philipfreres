<?php

declare(strict_types=1);
namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\{RequestEvent, ResponseEvent};
use Symfony\Component\HttpKernel\Profiler\Profiler;

final class InsightPrivacySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ?Profiler $profiler = null) {}
    public static function getSubscribedEvents(): array { return ['kernel.request' => ['request', 30], 'kernel.response' => ['response', 0]]; }
    public function request(RequestEvent $event): void
    {
        if (preg_match('~/administrateur/\d+/marches(?:/|$)~', $event->getRequest()->getPathInfo())) { $this->profiler?->disable(); }
    }
    public function response(ResponseEvent $event): void
    {
        if (preg_match('~/administrateur/\d+/marches(?:/|$)~', $event->getRequest()->getPathInfo())) {
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
            $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
        }
    }
}
