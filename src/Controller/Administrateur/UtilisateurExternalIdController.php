<?php
// src/Controller/Administrateur/UtilisateurExternalIdController.php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Utilisateur, UtilisateurExternalId};
use App\Enum\ExternalProvider;
use App\Form\Administrateur\UtilisateurExternalIdType;
use App\Repository\UtilisateurExternalIdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/administrateur/{entite}/utilisateurs/{utilisateur}/external-ids', name: 'app_administrateur_utilisateur_external_id_')]
final class UtilisateurExternalIdController extends AbstractController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly UtilisateurExternalIdRepository $repo,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, Utilisateur $utilisateur): Response
  {
    $items = $this->repo->findBy(['utilisateur' => $utilisateur], ['active' => 'DESC', 'createdAt' => 'DESC']);

    return $this->render('administrateur/utilisateur_external_id/index.html.twig', [
      'entite' => $entite,
      'utilisateur' => $utilisateur,
      'items' => $items,
    ]);
  }

  #[Route('/ajouter', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Utilisateur $utilisateur, Request $request): Response
  {
    $item = new UtilisateurExternalId(ExternalProvider::cases()[0], '');
    $item->setUtilisateur($utilisateur);

    $form = $this->createForm(UtilisateurExternalIdType::class, $item);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->em->persist($item);
      $this->em->flush();

      $this->addFlash('success', 'Identifiant externe ajouté.');
      return $this->redirectToRoute('app_administrateur_utilisateur_external_id_index', [
        'entite' => $entite->getId(),
        'utilisateur' => $utilisateur->getId(),
      ]);
    }

    return $this->render('administrateur/utilisateur_external_id/form.html.twig', [
      'entite' => $entite,
      'utilisateur' => $utilisateur,
      'item' => $item,
      'form' => $form,
      'modeEdition' => false,
    ]);
  }

  #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, Utilisateur $utilisateur, UtilisateurExternalId $item, Request $request): Response
  {
    if ($item->getUtilisateur()?->getId() !== $utilisateur->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(UtilisateurExternalIdType::class, $item);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->em->flush();

      $this->addFlash('success', 'Identifiant externe mis à jour.');
      return $this->redirectToRoute('app_administrateur_utilisateur_external_id_index', [
        'entite' => $entite->getId(),
        'utilisateur' => $utilisateur->getId(),
      ]);
    }

    return $this->render('administrateur/utilisateur_external_id/form.html.twig', [
      'entite' => $entite,
      'utilisateur' => $utilisateur,
      'item' => $item,
      'form' => $form,
      'modeEdition' => true,
    ]);
  }

  #[Route('/{id}/disable', name: 'disable', methods: ['POST'])]
  public function disable(Entite $entite, Utilisateur $utilisateur, UtilisateurExternalId $item, Request $request): Response
  {
    if ($item->getUtilisateur()?->getId() !== $utilisateur->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('disable_user_ext_' . $item->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_utilisateur_external_id_index', [
        'entite' => $entite->getId(),
        'utilisateur' => $utilisateur->getId(),
      ]);
    }

    $note = trim((string) $request->request->get('note'));
    $item->disable($note !== '' ? $note : null);
    $this->em->flush();

    $this->addFlash('success', 'Identifiant externe désactivé.');
    return $this->redirectToRoute('app_administrateur_utilisateur_external_id_index', [
      'entite' => $entite->getId(),
      'utilisateur' => $utilisateur->getId(),
    ]);
  }
}
