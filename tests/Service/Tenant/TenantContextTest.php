<?php

namespace App\Tests\Service\Tenant;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\EntiteRepository;
use App\Repository\UtilisateurEntiteRepository;
use App\Service\Tenant\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TenantContextTest extends TestCase
{
    public function testSuspendedCurrentAndLegacyEntityAreNotRestored(): void
    {
        $entite = $this->entity(12);
        $user = (new Utilisateur())->setEntite($entite);
        $membership = (new UtilisateurEntite())->setEntite($entite)->setStatus(UtilisateurEntite::STATUS_SUSPENDED);
        [$context, $session] = $this->context($entite, $membership);
        $session->set('current_entite_id', 12);

        self::assertNull($context->getCurrentEntiteForUser($user));
        self::assertFalse($session->has('current_entite_id'));
    }

    public function testSwitchRejectsInactiveMembershipBeforeSessionMutation(): void
    {
        $entite = $this->entity(12);
        $membership = (new UtilisateurEntite())->setEntite($entite)->setStatus(UtilisateurEntite::STATUS_INVITED);
        [$context, $session] = $this->context($entite, $membership);
        $session->set('current_entite_id', 23);
        try {
            $context->setCurrentEntite(new Utilisateur(), $entite);
            self::fail('Inactive membership must be rejected.');
        } catch (AccessDeniedException) {
            self::assertSame(23, $session->get('current_entite_id'));
        }
    }

    public function testSwitchChangesSessionWithoutMutatingManagedUser(): void
    {
        $entite = $this->entity(12);
        $oldEntite = $this->entity(23);
        $user = (new Utilisateur())->setEntite($oldEntite);
        [$context, $session] = $this->context($entite, (new UtilisateurEntite())->setEntite($entite));

        $context->setCurrentEntite($user, $entite);
        self::assertSame(12, $session->get('current_entite_id'));
        self::assertSame($oldEntite, $user->getEntite());
    }

    private function context(Entite $entite, UtilisateurEntite $membership): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $entities = $this->createStub(EntiteRepository::class);
        $entities->method('find')->willReturn($entite);
        $memberships = $this->createStub(UtilisateurEntiteRepository::class);
        $memberships->method('findMembership')->willReturn($membership);
        $memberships->method('findAllForUser')->willReturn([$membership]);
        return [new TenantContext($stack, $entities, $memberships), $session];
    }

    private function entity(int $id): Entite
    {
        $entite = new Entite();
        (new \ReflectionProperty(Entite::class, 'id'))->setValue($entite, $id);
        return $entite;
    }
}
