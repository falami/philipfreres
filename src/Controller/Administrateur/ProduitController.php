<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Produit, Utilisateur};
use App\Form\Administrateur\ProduitType as ProduitForm;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/produits', name: 'app_administrateur_produit_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')] // ⇦ mets TON permission dédiée si tu en as une
final class ProduitController extends AbstractController
{
  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->render('administrateur/produit/index.html.twig', [
      'entite' => $entite,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
  {
    $draw   = $request->request->getInt('draw', 0);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 10);

    if ($length <= 0 || $length > 500) {
      $length = 10;
    }

    $search  = (array) $request->request->all('search');
    $searchV = trim((string)($search['value'] ?? ''));

    // ✅ récup filtres (si tu en as)
    $f = $request->request->all('filters') ?? [];

    // Tri DataTables
    $order = (array) $request->request->all('order');
    $orderColIdx = (int) ($order[0]['column'] ?? 0);
    $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    // ✅ mapping colonnes "réelles" seulement (pas les ext_*)
    // 0 id, 1 sousCat, 2 cat, 3 alx, 4 total, 5 edenred, 6 actions
    $orderMap = [
      0 => 't.id',
      1 => 't.sousCategorieProduit',
      2 => 't.categorieProduit',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 't.id';

    // Query principale : Produit + external ids actifs
    $qb = $em->createQueryBuilder()
      ->select('t', 'x')
      ->from(Produit::class, 't')
      ->leftJoin('t.externalIds', 'x', 'WITH', 'x.active = true')
      ->andWhere('t.entite = :entite')
      ->setParameter('entite', $entite);

    // ✅ "Aucun" => aucun résultat (si tu utilises encore ces flags)
    if (
      !empty($f['providersNone']) || !empty($f['enginNone']) || !empty($f['employeNone'])
      || !empty($f['categorieNone']) || !empty($f['sousCategorieNone'])
    ) {
      $qb->andWhere('1=0');
    }

    // Search global
    if ($searchV !== '') {
      $qb->andWhere('(t.categorieProduit LIKE :q OR t.sousCategorieProduit LIKE :q)')
        ->setParameter('q', '%' . $searchV . '%');
    }

    // recordsTotal (sans search)
    $recordsTotal = (int) $em->createQueryBuilder()
      ->select('COUNT(DISTINCT t_t.id)')
      ->from(Produit::class, 't_t')
      ->andWhere('t_t.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()->getSingleScalarResult();

    // recordsFiltered
    $qbCount = clone $qb;
    $qbCount->resetDQLPart('select');
    $qbCount->resetDQLPart('orderBy');
    $recordsFiltered = (int) $qbCount
      ->select('COUNT(DISTINCT t.id)')
      ->getQuery()->getSingleScalarResult();

    /** @var Produit[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('t.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();

    $data = [];
    foreach ($rows as $p) {
      if (!$p instanceof Produit) continue;

      // ✅ listes par provider
      // ✅ listes par provider (labels)
      $extLabels = [
        \App\Enum\ExternalProvider::ALX->value     => [],
        \App\Enum\ExternalProvider::TOTAL->value   => [],
        \App\Enum\ExternalProvider::EDENRED->value => [],
      ];

      /** @var \App\Entity\ProduitExternalId $x */
      foreach ($p->getExternalIds() as $x) {
        if (!$x->isActive()) continue;

        $prov = $x->getProvider()->value;
        $raw  = trim((string) $x->getValue());
        if ($raw === '') continue;

        // ✅ Transforme "gasoil" -> "Gasoil" via l'enum
        $enum = \App\Enum\SousCategorieProduit::tryFrom($raw);

        // si valeur inconnue => fallback lisible (ou garde $raw)
        $label = $enum?->label() ?? $raw;

        // ✅ push sans doublon
        if (!\in_array($label, $extLabels[$prov], true)) {
          $extLabels[$prov][] = $label;
        }
      }

      $data[] = [
        'id'            => $p->getId(),
        'sousCategorie' => $p->getSousCategorieProduit()->label(),
        'categorie'     => $p->getCategorieProduit()->label(),

        // ✅ maintenant ce sont des labels
        'ext_alx'       => $extLabels[\App\Enum\ExternalProvider::ALX->value] ?? [],
        'ext_total'     => $extLabels[\App\Enum\ExternalProvider::TOTAL->value] ?? [],
        'ext_edenred'   => $extLabels[\App\Enum\ExternalProvider::EDENRED->value] ?? [],

        'actions'       => $this->renderView('administrateur/produit/_actions.html.twig', [
          'entite' => $entite,
          't'      => $p,
        ]),
      ];
    }

    return new JsonResponse([
      'draw'            => $draw,
      'recordsTotal'    => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data'            => $data,
    ]);
  }

  #[Route('/ajouter', name: 'ajouter', methods: ['GET', 'POST'])]
  #[Route('/modifier/{id}', name: 'modifier', methods: ['GET', 'POST'])]
  public function addEdit(Entite $entite, Request $request, EntityManagerInterface $em, ?Produit $produit = null): Response
  {
    $isEdit = (bool) $produit;
    if (!$produit) {
      $produit = new Produit();
      $produit->setEntite($entite);
    }

    $form = $this->createForm(ProduitForm::class, $produit);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->persist($produit);
      $em->flush();

      $this->addFlash('success', $isEdit ? 'Type de dépense modifié.' : 'Type de dépense ajouté.');
      return $this->redirectToRoute('app_administrateur_produit_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/produit/form.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
      'modeEdition' => $isEdit,
      't' => $produit,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
  public function delete(Entite $entite, EntityManagerInterface $em, Produit $produit, Request $request): RedirectResponse
  {
    $token = (string)$request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('delete_produit_' . $produit->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_produit_index', ['entite' => $entite->getId()]);
    }

    $id = $produit->getId();
    $em->remove($produit);
    $em->flush();

    $this->addFlash('success', 'Type de dépense #' . $id . ' supprimé.');
    return $this->redirectToRoute('app_administrateur_produit_index', ['entite' => $entite->getId()]);
  }

  #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function show(Entite $entite, Produit $produit): Response
  {
    return $this->render('administrateur/produit/show.html.twig', [
      'entite' => $entite,
      't' => $produit,
    ]);
  }
}
