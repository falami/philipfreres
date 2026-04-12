<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Produit, ProduitExternalId};
use App\Enum\ExternalProvider;
use App\Form\Administrateur\ProduitExternalIdType;
use App\Repository\ProduitExternalIdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/administrateur/{entite}/produits/{produit}/external-ids', name: 'app_administrateur_produit_external_id_')]
final class ProduitExternalIdController extends AbstractController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly ProduitExternalIdRepository $repo,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, Produit $produit): Response
  {
    $items = $this->repo->findBy(['produit' => $produit], ['active' => 'DESC', 'createdAt' => 'DESC']);

    return $this->render('administrateur/produit_external_id/index.html.twig', [
      'entite' => $entite,
      'produit' => $produit,
      'items' => $items,
    ]);
  }

  #[Route('/ajouter', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Produit $produit, Request $request): Response
  {
    $item = new ProduitExternalId(ExternalProvider::cases()[0], '');
    $item->setProduit($produit);

    $form = $this->createForm(ProduitExternalIdType::class, $item);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->em->persist($item);
      $this->em->flush();

      $this->addFlash('success', 'Correspondance ajoutée.');
      return $this->redirectToRoute('app_administrateur_produit_external_id_index', [
        'entite' => $entite->getId(),
        'produit' => $produit->getId(),
      ]);
    }

    return $this->render('administrateur/produit_external_id/form.html.twig', [
      'entite' => $entite,
      'produit' => $produit,
      'item' => $item,
      'form' => $form->createView(),
      'modeEdition' => false,
    ]);
  }

  #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, Produit $produit, ProduitExternalId $item, Request $request): Response
  {
    if ($item->getProduit()?->getId() !== $produit->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(ProduitExternalIdType::class, $item);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->em->flush();

      $this->addFlash('success', 'Correspondance mise à jour.');
      return $this->redirectToRoute('app_administrateur_produit_external_id_index', [
        'entite' => $entite->getId(),
        'produit' => $produit->getId(),
      ]);
    }

    return $this->render('administrateur/produit_external_id/form.html.twig', [
      'entite' => $entite,
      'produit' => $produit,
      'item' => $item,
      'form' => $form->createView(),
      'modeEdition' => true,
    ]);
  }

  #[Route('/{id}/disable', name: 'disable', methods: ['POST'])]
  public function disable(Entite $entite, Produit $produit, ProduitExternalId $item, Request $request): Response
  {
    if ($item->getProduit()?->getId() !== $produit->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('disable_type_dep_ext_' . $item->getId(), (string)$request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_produit_external_id_index', [
        'entite' => $entite->getId(),
        'produit' => $produit->getId(),
      ]);
    }

    $note = trim((string)$request->request->get('note'));
    $item->disable($note !== '' ? $note : null);
    $this->em->flush();

    $this->addFlash('success', 'Correspondance désactivée.');
    return $this->redirectToRoute('app_administrateur_produit_external_id_index', [
      'entite' => $entite->getId(),
      'produit' => $produit->getId(),
    ]);
  }
}
