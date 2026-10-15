<?php

namespace App\Tests\Controller;

use App\Controller\WorkspaceController;
use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;
use App\Security\RedirectAfterLogin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class WorkspaceControllerTest extends TestCase
{
    public function testRememberedUserOnlySeesActivePersistedMemberships(): void
    {
        [$controller, $container, $user] = $this->controller();
        $active = (new UtilisateurEntite())->setEntite($this->entity(12));
        $suspended = (new UtilisateurEntite())->setEntite($this->entity(23))->setStatus(UtilisateurEntite::STATUS_SUSPENDED);
        $invited = (new UtilisateurEntite())->setEntite($this->entity(34))->setStatus(UtilisateurEntite::STATUS_INVITED);
        $repo = $this->createStub(UtilisateurEntiteRepository::class);
        $repo->method('findAllForUser')->willReturn([$active, $suspended, $invited, new UtilisateurEntite()]);
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->with('workspace/index.html.twig', ['memberships' => [$active], 'user' => $user])->willReturn('workspace');
        $container->set('twig', $twig);

        $response = $controller->index($repo);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    #[DataProvider('invalidSwitches')]
    public function testInvalidSwitchNeverChangesSession(bool $validCsrf, ?string $status): void
    {
        [$controller] = $this->controller($validCsrf);
        $entite = $this->entity(12);
        $repo = $this->createMock(UtilisateurEntiteRepository::class);
        $membership = $status === null ? null : (new UtilisateurEntite())->setEntite($entite)->setStatus($status);
        $repo->expects($validCsrf ? self::once() : self::never())->method('findMembership')->willReturn($membership);
        $request = $this->request();
        $request->getSession()->set('current_entite_id', 23);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('generate');

        try {
            $controller->switchEntite($entite, $repo, $request, new RedirectAfterLogin($router, $repo));
            self::fail('The unauthorized switch must fail.');
        } catch (AccessDeniedException) {
            self::assertSame(23, $request->getSession()->get('current_entite_id'));
        }
    }

    public static function invalidSwitches(): iterable
    {
        yield 'csrf' => [false, UtilisateurEntite::STATUS_ACTIVE];
        yield 'not a member' => [true, null];
        yield 'suspended' => [true, UtilisateurEntite::STATUS_SUSPENDED];
        yield 'invited' => [true, UtilisateurEntite::STATUS_INVITED];
    }

    public function testRememberedUserCanSwitchWithCsrfAndActiveMembership(): void
    {
        [$controller, , $user] = $this->controller();
        $entite = $this->entity(12);
        $oldEntite = $this->entity(23);
        $user->setEntite($oldEntite);
        $repo = $this->createStub(UtilisateurEntiteRepository::class);
        $repo->method('findMembership')->willReturn((new UtilisateurEntite())->setEntite($entite)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $request = $this->request();
        $request->getSession()->set('_security.main.target_path', '/old-target');
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')->with('app_administrateur_fuel_dashboard', ['entite' => 12])->willReturn('/fr/administrateur/12/carburant/dashboard');

        $response = $controller->switchEntite($entite, $repo, $request, new RedirectAfterLogin($router, $repo));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(12, $request->getSession()->get('current_entite_id'));
        self::assertFalse($request->getSession()->has('_security.main.target_path'));
        self::assertSame($oldEntite, $user->getEntite());
    }

    private function controller(bool $csrfValid = true): array
    {
        $user = new Utilisateur();
        $storage = new TokenStorage();
        $storage->setToken(new RememberMeToken($user, 'main'));
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::once())->method('isGranted')->with('IS_AUTHENTICATED_REMEMBERED')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $csrfValid && $token->getId() === 'switch_entite_12' && $token->getValue() === 'valid-token');
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $container->set('security.authorization_checker', $auth);
        $container->set('security.csrf.token_manager', $csrf);
        $controller = new WorkspaceController();
        $controller->setContainer($container);
        return [$controller, $container, $user];
    }

    private function request(): Request
    {
        $request = Request::create('/fr/workspace/switch/12', 'POST', ['_token' => 'valid-token']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        return $request;
    }

    private function entity(int $id): Entite
    {
        $entite = new Entite();
        (new \ReflectionProperty(Entite::class, 'id'))->setValue($entite, $id);
        return $entite;
    }
}
