<?php

namespace App\Tests\Security;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;
use App\Security\RedirectAfterLogin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;

final class RedirectAfterLoginTest extends TestCase
{
    #[DataProvider('unsafeTargets')]
    public function testUnsafeOrForeignTargetsAreConsumedAndIgnored(mixed $target): void
    {
        $request = $this->request();
        $request->getSession()->set('_security.main.target_path', $target);
        $redirect = $this->redirector([$this->membership(12, UtilisateurEntite::TENANT_ADMIN)]);

        self::assertSame('/admin/12', $redirect->redirect($request, new Utilisateur())->getTargetUrl());
        self::assertFalse($request->getSession()->has('_security.main.target_path'));
        self::assertSame(12, $request->getSession()->get('current_entite_id'));
    }

    public static function unsafeTargets(): iterable
    {
        yield ['https://evil.example/fr/administrateur/12/engin'];
        yield ['//evil.example/fr/administrateur/12/engin'];
        yield ['/\\evil.example/fr/administrateur/12/engin'];
        yield ['/%2f%2fevil.example/fr/administrateur/12/engin'];
        yield ['/%255cevil.example/fr/administrateur/12/engin'];
        yield ['https://app.example@evil.example/fr/administrateur/12/engin'];
        yield ['https://evil.example@app.example/fr/administrateur/12/engin'];
        yield ['http://app.example/fr/administrateur/12/engin'];
        yield ['https://app.example:444/fr/administrateur/12/engin'];
        yield ["/fr/administrateur/12/engin\r\nLocation:https://evil.example"];
        yield ['/fr/administrateur/12/%0d%0aLocation:evil'];
        yield ['/fr/administrateur/12/%252e%252e/other'];
        yield ['/fr/administrateur/12/../13/engin'];
        yield ['/fr/administrateur/12/engin#fragment'];
        yield ['/fr/administrateur/13/engin'];
        yield ['javascript:alert(1)'];
        yield [['_unexpected' => 'array']];
    }

    #[DataProvider('localTargets')]
    public function testActiveMemberCanResumeSameOriginTenantTarget(string $target): void
    {
        $request = $this->request();
        $request->getSession()->set('_security.main.target_path', $target);
        $redirect = $this->redirector([$this->membership(12, UtilisateurEntite::TENANT_ADMIN)]);

        self::assertSame('/fr/administrateur/12/engin?page=2', $redirect->redirect($request, new Utilisateur())->getTargetUrl());
        self::assertFalse($request->getSession()->has('_security.main.target_path'));
        self::assertSame(12, $request->getSession()->get('current_entite_id'));
    }

    public static function localTargets(): iterable
    {
        yield ['/fr/administrateur/12/engin?page=2'];
        yield ['https://app.example/fr/administrateur/12/engin?page=2'];
        yield ['https://APP.EXAMPLE:443/fr/administrateur/12/engin?page=2'];
    }

    public function testNoMembershipAndSuspendedMembershipsUseWorkspace(): void
    {
        $request = $this->request();
        $request->getSession()->set('current_entite_id', 12);
        $membership = $this->membership(12, UtilisateurEntite::TENANT_ADMIN)->setStatus(UtilisateurEntite::STATUS_SUSPENDED);

        self::assertSame('/workspace', $this->redirector([$membership])->redirect($request, new Utilisateur())->getTargetUrl());
        self::assertFalse($request->getSession()->has('current_entite_id'));
        self::assertSame('/workspace', $this->redirector([])->redirect($request, new Utilisateur())->getTargetUrl());
    }

    public function testMultipleMembershipsUseWorkspaceOrRememberedActiveEntity(): void
    {
        $request = $this->request();
        $redirect = $this->redirector([
            $this->membership(12, UtilisateurEntite::TENANT_ADMIN),
            $this->membership(23, UtilisateurEntite::TENANT_CHEF),
        ]);
        self::assertSame('/workspace', $redirect->redirect($request, new Utilisateur())->getTargetUrl());
        $request->getSession()->set('current_entite_id', 23);
        self::assertSame('/chantier/23', $redirect->redirect($request, new Utilisateur())->getTargetUrl());
    }

    #[DataProvider('roleDestinations')]
    public function testRoleDestinations(string $role, string $destination): void
    {
        $request = $this->request();
        $request->getSession()->set('_security.main.target_path', '/fr/premium/12/entite/12');
        $redirect = $this->redirector([$this->membership(12, $role)]);
        self::assertSame($destination, $redirect->redirect($request, new Utilisateur())->getTargetUrl());
    }

    public static function roleDestinations(): iterable
    {
        yield [UtilisateurEntite::TENANT_CHEF, '/chantier/12'];
        yield [UtilisateurEntite::TENANT_EMPLOYE, '/employe/12'];
    }

    public function testChefCanResumeChantierButNotAdminOnlyPage(): void
    {
        $request = $this->request();
        $redirect = $this->redirector([$this->membership(12, UtilisateurEntite::TENANT_CHEF)]);
        $request->getSession()->set('_security.main.target_path', '/fr/administrateur/12/chantier/42');
        self::assertSame('/fr/administrateur/12/chantier/42', $redirect->redirect($request, new Utilisateur())->getTargetUrl());
        $request->getSession()->set('_security.main.target_path', '/fr/administrateur/12/engin');
        self::assertSame('/chantier/12', $redirect->redirect($request, new Utilisateur())->getTargetUrl());
    }

    private function redirector(array $memberships): RedirectAfterLogin
    {
        $repo = $this->createStub(UtilisateurEntiteRepository::class);
        $repo->method('findAllForUser')->willReturn($memberships);
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => match ($route) {
            'app_workspace' => '/workspace',
            'app_administrateur_fuel_dashboard' => '/admin/' . $parameters['entite'],
            'app_administrateur_chantier_index' => '/chantier/' . $parameters['entite'],
            'app_employe_access_denied' => '/employe/' . $parameters['entite'],
            default => throw new \LogicException('Unexpected route: ' . $route),
        });
        return new RedirectAfterLogin($router, $repo);
    }

    private function request(): Request
    {
        $request = Request::create('https://app.example/fr/login');
        $request->setSession(new Session(new MockArraySessionStorage()));
        return $request;
    }

    private function membership(int $id, string $role): UtilisateurEntite
    {
        $entite = new Entite();
        (new \ReflectionProperty(Entite::class, 'id'))->setValue($entite, $id);
        return (new UtilisateurEntite())->setEntite($entite)->setRoles([$role]);
    }
}
