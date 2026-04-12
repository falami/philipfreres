<?php

namespace App\Controller\Super;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Form\Super\UtilisateurEntiteAssignType;
use App\Repository\EntiteRepository;
use App\Repository\UtilisateurEntiteRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;

#[IsGranted('ROLE_SUPER')]
#[Route('/super/{entite}', name: 'app_super_admin_')]
final class SuperAdminController extends AbstractController
{

  public function __construct(
    private readonly UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}
  #[Route('/console', name: 'console', methods: ['GET'])]
  public function console(
    Entite $entite,
    Request $request,
    EntiteRepository $entiteRepo,
    UtilisateurRepository $userRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $tab = (string)$request->query->get('tab', 'entites');
    $qEnt = trim((string)$request->query->get('qEnt', ''));
    $qUser = trim((string)$request->query->get('qUser', ''));

    $entites = $entiteRepo->searchAll($qEnt, 250);
    $users = $userRepo->searchAll($qUser, 250);

    return $this->render('super/console/index.html.twig', [
      'tab' => $tab,
      'qEnt' => $qEnt,
      'qUser' => $qUser,
      'entites' => $entites,
      'users' => $users,
      'entite' => $entite,


    ]);
  }


  /**
   * Assigner un rôle à un utilisateur dans une entité (crée/maj UtilisateurEntite).
   */
  #[Route('/assign', name: 'assign', methods: ['GET', 'POST'])]
  public function assign(
    Entite $entite,
    Request $request,
    EntityManagerInterface $em,
    UtilisateurEntiteRepository $ueRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $ue = new UtilisateurEntite();
    $ue->setCreateur($this->getUser());
    $ue->ensureCouleur();

    $form = $this->createForm(UtilisateurEntiteAssignType::class, $ue);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      // si déjà existant => update au lieu de créer
      $existing = $ueRepo->findOneBy([
        'utilisateur' => $ue->getUtilisateur(),
        'entite' => $ue->getEntite(),
      ]);

      if ($existing) {
        $roles = $ue->getRoles();
        $existing->setRoles($roles ?: [UtilisateurEntite::TENANT_EMPLOYE]);

        $existing->setFonction($ue->getFonction());
        $existing->setCouleur($ue->getCouleur());
        $existing->ensureCouleur();
      } else {
        $ue->ensureCouleur();
        $em->persist($ue);
      }

      $em->flush();

      $this->addFlash('success', 'Rôle attribué / mis à jour.');
      return $this->redirectToRoute('app_super_admin_console', [
        'tab' => 'users',
        'entite' => $entite->getId(),

      ]);
    }

    return $this->render('super/console/assign.html.twig', [
      'form' => $form->createView(),
      'entite' => $entite,


    ]);
  }

  /**
   * (Option) Retirer un rattachement user<->entite depuis la console
   */
  #[Route('/links/{id}/delete', name: 'link_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function deleteLink(
    Entite $entite,
    UtilisateurEntite $ue,
    Request $request,
    EntityManagerInterface $em
  ): RedirectResponse {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    if (!$this->isCsrfTokenValid('ue_del_' . $ue->getId(), (string)$request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_super_admin_console', [
        'tab' => 'users',
        'entite' => $entite->getId(),

      ]);
    }

    $em->remove($ue);
    $em->flush();

    $this->addFlash('warning', 'Rattachement supprimé.');
    return $this->redirectToRoute('app_super_admin_console', [
      'tab' => 'users',
      'entite' => $entite->getId(),


    ]);
  }
}
