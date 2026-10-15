<?php

namespace App\Controller;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;
use App\Security\RedirectAfterLogin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WorkspaceController extends AbstractController
{
    #[Route('/workspace', name: 'app_workspace', methods: ['GET'])]
    public function index(UtilisateurEntiteRepository $ueRepo): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException();
        }

        $memberships = array_values(array_filter(
            $ueRepo->findAllForUser($user),
            static fn (UtilisateurEntite $membership): bool => $membership->isActive()
                && $membership->getEntite()?->getId() !== null,
        ));

        $response = $this->render('workspace/index.html.twig', [
            'memberships' => $memberships,
            'user' => $user,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/workspace/switch/{id}', name: 'app_workspace_switch', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function switchEntite(
        Entite $entite,
        UtilisateurEntiteRepository $ueRepo,
        Request $request,
        RedirectAfterLogin $redirect,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('switch_entite_' . $entite->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La demande a expiré. Veuillez réessayer.');
        }

        $membership = $ueRepo->findMembership($user, $entite);
        if (!$membership || !$membership->isActive()) {
            throw $this->createAccessDeniedException('Aucun accès actif à cette entité.');
        }

        // Selecting an entity changes this browser's session, never business data.
        $request->getSession()->remove('_security.main.target_path');
        return $redirect->redirectToMembership($request, $membership);
    }
}
