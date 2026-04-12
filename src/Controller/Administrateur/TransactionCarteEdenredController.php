<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, TransactionCarteEdenred};
use App\Form\Administrateur\{TransactionCarteEdenredImportType, TransactionCarteEdenredType};
use App\Security\Permission\TenantPermission;
use App\Service\Import\TransactionCarteEdenredExcelImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/transaction/carte/edenred', name: 'app_administrateur_tce_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class TransactionCarteEdenredController extends AbstractController
{
  public function __construct(
    private readonly TransactionCarteEdenredExcelImporter $importer,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/transaction/carte/edenred/index.html.twig', [
      'entite' => $entite,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
  {
    $start   = $request->request->getInt('start', 0);
    $length  = $request->request->getInt('length', 10);

    $search  = $request->request->all('search');
    $searchV = trim((string)($search['value'] ?? ''));

    $order = $request->request->all('order');
    $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
    $orderDir = strtolower($order[0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    // map colonnes DataTables -> champs DQL
    $orderMap = [
      0 => 't.id',
      1 => 't.dateTransaction',
      2 => 't.numeroTransaction',
      3 => 't.carteNumero',
      4 => 't.produit',
      5 => 't.siteLibelleCourt',
      6 => 't.montantTtc',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 't.id';

    $repo = $em->getRepository(TransactionCarteEdenred::class);

    $recordsTotal = (int) $repo->createQueryBuilder('t')
      ->select('COUNT(t.id)')
      ->andWhere('t.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()->getSingleScalarResult();

    $qb = $repo->createQueryBuilder('t')
      ->andWhere('t.entite = :entite')
      ->setParameter('entite', $entite);

    if ($searchV !== '') {
      $qb->andWhere('
                t.enseigne LIKE :q
                OR t.clientNom LIKE :q
                OR t.carteNumero LIKE :q
                OR t.numeroTransaction LIKE :q
                OR t.produit LIKE :q
                OR t.siteLibelle LIKE :q
                OR t.siteLibelleCourt LIKE :q
                OR t.immatriculation LIKE :q
                OR t.numeroFacture LIKE :q
            ')->setParameter('q', '%' . $searchV . '%');
    }

    $qbCount = (clone $qb);
    $qbCount->resetDQLPart('select');
    $qbCount->resetDQLPart('orderBy');
    $recordsFiltered = (int) $qbCount->select('COUNT(DISTINCT t.id)')
      ->getQuery()->getSingleScalarResult();

    /** @var TransactionCarteEdenred[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('t.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = array_map(function (TransactionCarteEdenred $t) use ($entite) {
      return [
        'id' => $t->getId(),
        'date' => $t->getDateTransaction()?->format('d/m/Y') ?? '—',
        'numTxn' => $t->getNumeroTransaction() ?: '—',
        'carte' => $t->getCarteNumero() ?: '—',
        'produit' => $t->getProduit() ?: '—',
        'site' => $t->getSiteLibelleCourt() ?: ($t->getSiteLibelle() ?: '—'),
        'ttc' => $t->getMontantTtc() ?? '—',
        'actions' => $this->renderView('administrateur/transaction/carte/edenred/_actions.html.twig', [
          't' => $t,
          'entite' => $entite,
        ]),
      ];
    }, $rows);

    return new JsonResponse([
      'draw'            => (int)$request->request->get('draw'),
      'recordsTotal'    => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data'            => $data,
    ]);
  }

  #[Route('/show/{id}', name: 'show', methods: ['GET'])]
  public function show(Entite $entite, TransactionCarteEdenred $t): Response
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    return $this->render('administrateur/transaction/carte/edenred/show.html.twig', [
      'entite' => $entite,
      't' => $t,
    ]);
  }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request, EntityManagerInterface $em): Response
  {
    $t = (new TransactionCarteEdenred())->setEntite($entite);

    $form = $this->createForm(TransactionCarteEdenredType::class, $t);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      // clé import si ajout manuel (anti-doublon cohérent)
      if (!$t->getImportKey()) {
        $parts = [
          (string)$entite->getId(),
          (string)($t->getNumeroTransaction() ?? ''),
          (string)($t->getDateTransaction()?->format('Y-m-d') ?? ''),
          (string)($t->getCarteNumero() ?? ''),
          (string)($t->getProduit() ?? ''),
          (string)($t->getMontantTtc() ?? ''),
        ];
        $t->setImportKey(sha1(implode('|', $parts)));
      }

      $em->persist($t);
      $em->flush();

      $this->addFlash('success', 'Transaction EDENRED ajoutée.');
      return $this->redirectToRoute('app_administrateur_tce_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/transaction/carte/edenred/form.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
      'modeEdition' => false,
    ]);
  }

  #[Route('/edit/{id}', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, TransactionCarteEdenred $t, Request $request, EntityManagerInterface $em): Response
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(TransactionCarteEdenredType::class, $t);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->flush();
      $this->addFlash('success', 'Transaction EDENRED modifiée.');
      return $this->redirectToRoute('app_administrateur_tce_show', ['entite' => $entite->getId(), 'id' => $t->getId()]);
    }

    return $this->render('administrateur/transaction/carte/edenred/form.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
      'modeEdition' => true,
      't' => $t,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
  public function delete(Entite $entite, TransactionCarteEdenred $t, Request $request, EntityManagerInterface $em): RedirectResponse
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $token = (string)$request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('delete_tce_' . $t->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_tce_index', ['entite' => $entite->getId()]);
    }

    $id = $t->getId();
    $em->remove($t);
    $em->flush();

    $this->addFlash('success', 'Transaction #' . $id . ' supprimée.');
    return $this->redirectToRoute('app_administrateur_tce_index', ['entite' => $entite->getId()]);
  }

  #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
  public function import(Entite $entite, Request $request): Response
  {
    $form = $this->createForm(TransactionCarteEdenredImportType::class);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      /** @var UploadedFile|null $file */
      $file = $form->get('file')->getData();

      if (!$file instanceof UploadedFile) {
        $this->addFlash('danger', 'Aucun fichier reçu.');
        return $this->redirectToRoute('app_administrateur_tce_import', ['entite' => $entite->getId()]);
      }

      $res = $this->importer->import($entite, $file);

      if (!empty($res['errors'])) {
        $this->addFlash('warning', "Import terminé avec erreurs : {$res['imported']} importées, {$res['skipped']} ignorées.");
        foreach (array_slice($res['errors'], 0, 5) as $e) {
          $this->addFlash('danger', $e);
        }
      } else {
        $this->addFlash('success', "Import OK : {$res['imported']} lignes importées, {$res['skipped']} ignorées (doublons/vides).");
      }

      return $this->redirectToRoute('app_administrateur_tce_import', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/transaction/carte/edenred/import.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
    ]);
  }
}
