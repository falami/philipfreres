<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AccountSecurityController extends AbstractController
{
    #[Route('/compte/securite', name: 'app_account_security', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('security/devices.html.twig');
    }

    #[Route('/compte/securite/revoquer', name: 'app_account_sessions_revoke', methods: ['POST'])]
    public function revoke(Request $request, EntityManagerInterface $em, Security $security): Response
    {
        if (!$this->isCsrfTokenValid('revoke_sessions', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Formulaire expiré. Rechargez la page.');
        }
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) throw $this->createAccessDeniedException();
        // Atomic increment: concurrent revocations cannot restore an older version.
        $em->createQuery('UPDATE App\\Entity\\Utilisateur u SET u.securityVersion = u.securityVersion + 1 WHERE u.id = :id')
            ->setParameter('id', $user->getId())->execute();
        $em->refresh($user);
        return $security->logout(false) ?? $this->redirectToRoute('app_login');
    }
}
