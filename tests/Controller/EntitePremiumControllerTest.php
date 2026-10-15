<?php

namespace App\Tests\Controller;

use App\Controller\Premium\EntitePremiumController;
use App\Entity\Entite;
use App\Security\Permission\TenantPermission;
use App\Service\FileUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class EntitePremiumControllerTest extends TestCase
{
    public function testCrossTenantEditFailsBeforeBindingFormOrUsingMutationServices(): void
    {
        // Deliberately leave mutation services uninitialized: any use before the
        // tenant boundary check fails this test, without a database or filesystem.
        $controller = (new \ReflectionClass(EntitePremiumController::class))->newInstanceWithoutConstructor();
        $controller->setContainer(new Container());
        $this->expectException(AccessDeniedException::class);
        $controller->modifier($this->entity(12), $this->entity(23), new FileUploader(), Request::create('/fr/premium/12/entite/modifier/23', 'POST'));
    }

    public function testActualEditTargetRequiresPermissionEvenWhenCalledDirectly(): void
    {
        $controller = (new \ReflectionClass(EntitePremiumController::class))->newInstanceWithoutConstructor();
        $target = $this->entity(12);
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::once())->method('isGranted')->with(TenantPermission::UTILISATEUR_MANAGE, self::identicalTo($target))->willReturn(false);
        $container = new Container();
        $container->set('security.authorization_checker', $auth);
        $controller->setContainer($container);

        $this->expectException(AccessDeniedException::class);
        $controller->modifier($this->entity(12), $target, new FileUploader(), Request::create('/fr/premium/12/entite/modifier/12', 'POST'));
    }

    public function testCrossTenantIndexIsRejected(): void
    {
        $controller = (new \ReflectionClass(EntitePremiumController::class))->newInstanceWithoutConstructor();
        $controller->setContainer(new Container());
        $this->expectException(AccessDeniedException::class);
        $controller->index($this->entity(12), 23);
    }

    private function entity(int $id): Entite
    {
        $entite = new Entite();
        (new \ReflectionProperty(Entite::class, 'id'))->setValue($entite, $id);
        return $entite;
    }
}
