<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventSubscriber\ProductionHttpsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ProductionHttpsSubscriberTest extends TestCase
{
    public function testLocalHttpRemainsAvailableInDevelopmentAndTests(): void
    {
        foreach (['dev', 'test'] as $environment) {
            $request = Request::create('http://localhost:8888/fr/login');
            $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
            (new ProductionHttpsSubscriber($environment))->enforceHttps($event);
            self::assertFalse($event->hasResponse());
        }
    }

    public function testProductionHttpRedirectUsesCanonicalOriginAndPreservesRequestTarget(): void
    {
        $request = Request::create('http://attacker.example.test/fr/login?next=%2Ffr%2Fworkspace', 'POST', ['_username' => 'synthetic@example.test']);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new ProductionHttpsSubscriber('prod'))->enforceHttps($event);
        self::assertTrue($event->hasResponse());
        self::assertSame(308, $event->getResponse()->getStatusCode());
        self::assertSame('https://app.philipfreres.com/fr/login?next=%2Ffr%2Fworkspace', $event->getResponse()->headers->get('Location'));
        self::assertTrue($event->isPropagationStopped(), 'The redirect must stop processing before a controller can write data.');
    }

    public function testProductionHttpsContinuesAndSetsOneYearHsts(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://app.philipfreres.com/fr/login');
        $requestEvent = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber = new ProductionHttpsSubscriber('prod');
        $subscriber->enforceHttps($requestEvent);
        self::assertFalse($requestEvent->hasResponse());
        $response = new Response();
        $subscriber->protectTransport(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    public function testHstsIsNotEmittedForDevelopmentOrInsecureHttp(): void
    {
        foreach ([['dev', 'https'], ['test', 'https'], ['prod', 'http']] as [$environment, $scheme]) {
            $response = new Response();
            $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create($scheme.'://localhost/fr/login'), HttpKernelInterface::MAIN_REQUEST, $response);
            (new ProductionHttpsSubscriber($environment))->protectTransport($event);
            self::assertFalse($response->headers->has('Strict-Transport-Security'));
        }
    }

    public function testSubrequestsDoNotRedirectOrChangeTransportPolicy(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber = new ProductionHttpsSubscriber('prod');
        $requestEvent = new RequestEvent($kernel, Request::create('http://localhost/_fragment'), HttpKernelInterface::SUB_REQUEST);
        $subscriber->enforceHttps($requestEvent);
        self::assertFalse($requestEvent->hasResponse());
        $response = new Response();
        $subscriber->protectTransport(new ResponseEvent($kernel, Request::create('https://app.philipfreres.com/_fragment'), HttpKernelInterface::SUB_REQUEST, $response));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }
}
