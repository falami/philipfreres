<?php
// src/Controller/Administrateur/EnginExternalIdController.php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Engin, EnginExternalId};
use App\Enum\ExternalProvider;
use App\Form\Administrateur\EnginExternalIdType;
use App\Repository\EnginExternalIdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/engins/{engin}/external-ids', name: 'app_administrateur_engin_external_id_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class EnginExternalIdController extends AbstractController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly EnginExternalIdRepository $repo,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, Engin $engin): Response
  {
    $items = $this->repo->findBy(['engin' => $engin], ['active' => 'DESC', 'createdAt' => 'DESC']);

    return $this->render('administrateur/engin_external_id/index.html.twig', [
      'entite' => $entite,
      'engin' => $engin,
      'items' => $items,
    ]);
  }

  #[Route('/ajouter', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Engin $engin, Request $request): Response
  {
    // on crée l'objet avec des valeurs par défaut (provider/value seront écrasés via form)
    $item = new EnginExternalId(ExternalProvider::cases()[0], ''); // placeholder safe
    $item->setEngin($engin);

    $form = $this->createForm(EnginExternalIdType::class, $item);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->em->persist($item);
      $this->em->flush();

      $this->addFlash('success', 'Identifiant externe ajouté.');
      return $this->redirectToRoute('app_administrateur_engin_external_id_index', [
        'entite' => $entite->getId(),
        'engin' => $engin->getId(),
      ]);
    }

    return $this->render('administrateur/engin_external_id/form.html.twig', [
      'entite' => $entite,
      'engin' => $engin,
      'item' => $item,
      'form' => $form,
      'modeEdition' => false,
    ]);
  }

  #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, Engin $engin, EnginExternalId $item, Request $request): Response
  {
    if ($item->getEngin()?->getId() !== $engin->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(EnginExternalIdType::class, $item);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->em->flush();

      $this->addFlash('success', 'Identifiant externe mis à jour.');
      return $this->redirectToRoute('app_administrateur_engin_external_id_index', [
        'entite' => $entite->getId(),
        'engin' => $engin->getId(),
      ]);
    }

    return $this->render('administrateur/engin_external_id/form.html.twig', [
      'entite' => $entite,
      'engin' => $engin,
      'item' => $item,
      'form' => $form,
      'modeEdition' => true,
    ]);
  }

  #[Route('/{id}/disable', name: 'disable', methods: ['POST'])]
  public function disable(Entite $entite, Engin $engin, EnginExternalId $item, Request $request): Response
  {
    if ($item->getEngin()?->getId() !== $engin->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('disable_engin_ext_' . $item->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_engin_external_id_index', [
        'entite' => $entite->getId(),
        'engin' => $engin->getId(),
      ]);
    }

    $note = trim((string) $request->request->get('note'));
    $item->disable($note !== '' ? $note : null);
    $this->em->flush();

    $this->addFlash('success', 'Identifiant externe désactivé.');
    return $this->redirectToRoute('app_administrateur_engin_external_id_index', [
      'entite' => $entite->getId(),
      'engin' => $engin->getId(),
    ]);
  }
}
