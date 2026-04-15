<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, TransactionCarteTotal};
use App\Form\Administrateur\TransactionCarteTotalImportType;
use App\Form\Administrateur\TransactionCarteTotalType;
use App\Security\Permission\TenantPermission;
use App\Service\Import\TransactionCarteTotalExcelImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/transaction/carte/total', name: 'app_administrateur_tct_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')] // ⚠️ adapte la permission si tu en as une dédiée
final class TransactionCarteTotalController extends AbstractController
{
  public function __construct(
    private readonly TransactionCarteTotalExcelImporter $importer,
  ) {}
  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/transaction/carte/total/index.html.twig', [
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

    // ⚠️ map colonnes DataTables -> champs DQL
    $orderMap = [
      0 => 't.id',
      1 => 't.dateTransaction',
      2 => 't.heureTransaction',
      3 => 't.numeroCarte',
      4 => 't.produit',
      5 => 't.ville',
      6 => 't.montantTtcEur',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 't.id';

    $repo = $em->getRepository(TransactionCarteTotal::class);

    // recordsTotal
    $recordsTotal = (int) $repo->createQueryBuilder('t')
      ->select('COUNT(t.id)')
      ->andWhere('t.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()->getSingleScalarResult();

    // base query
    $qb = $repo->createQueryBuilder('t')
      ->andWhere('t.entite = :entite')
      ->setParameter('entite', $entite);

    // search (large)
    if ($searchV !== '') {
      $qb->andWhere('
                t.compteClient LIKE :q
                OR t.raisonSociale LIKE :q
                OR t.numeroCarte LIKE :q
                OR t.numeroTransaction LIKE :q
                OR t.produit LIKE :q
                OR t.ville LIKE :q
                OR t.pays LIKE :q
                OR t.numeroFacture LIKE :q
            ')
        ->setParameter('q', '%' . $searchV . '%');
    }

    // recordsFiltered
    $qbCount = (clone $qb);
    $qbCount->resetDQLPart('select');
    $qbCount->resetDQLPart('orderBy');
    $recordsFiltered = (int) $qbCount->select('COUNT(DISTINCT t.id)')
      ->getQuery()->getSingleScalarResult();

    /** @var TransactionCarteTotal[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('t.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = array_map(function (TransactionCarteTotal $t) use ($entite) {
      $date = $t->getDateTransaction()?->format('d/m/Y') ?? '—';
      $heure = $t->getHeureTransaction()?->format('H:i') ?? '—';

      return [
        'id' => $t->getId(),
        'date' => $date,
        'heure' => $heure,
        'carte' => $t->getNumeroCarte() ?: '—',
        'produit' => $t->getProduit() ?: '—',
        'ville' => $t->getVille() ?: '—',
        'ttc' => $t->getMontantTtcEur() ?? '—',
        'actions' => $this->renderView('administrateur/transaction/carte/total/_actions.html.twig', [
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
  public function show(Entite $entite, TransactionCarteTotal $t): Response
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    return $this->render('administrateur/transaction/carte/total/show.html.twig', [
      'entite' => $entite,
      't' => $t,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
  public function delete(Entite $entite, TransactionCarteTotal $t, Request $request, EntityManagerInterface $em): RedirectResponse
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $token = (string)$request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('delete_tct_' . $t->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('index', ['entite' => $entite->getId()]);
    }

    $id = $t->getId();
    $em->remove($t);
    $em->flush();

    $this->addFlash('success', 'Transaction #' . $id . ' supprimée.');
    return $this->redirectToRoute('index', ['entite' => $entite->getId()]);
  }


  #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
  public function import(Entite $entite, Request $request): Response
  {
    $form = $this->createForm(TransactionCarteTotalImportType::class);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      /** @var UploadedFile|null $file */
      $file = $form->get('file')->getData();

      if (!$file instanceof UploadedFile) {
        $this->addFlash('danger', 'Aucun fichier reçu.');
        return $this->redirectToRoute('app_administrateur_tct_import', ['entite' => $entite->getId()]);
      }

      $res = $this->importer->import($entite, $file);

      if (!empty($res['errors'])) {
        $this->addFlash('warning', "Import terminé avec erreurs : {$res['imported']} importées, {$res['skipped']} ignorées.");
        // on affiche max 5 erreurs
        foreach (array_slice($res['errors'], 0, 5) as $e) {
          $this->addFlash('danger', $e);
        }
      } else {
        $this->addFlash('success', "Import OK : {$res['imported']} lignes importées, {$res['skipped']} ignorées (doublons/vides).");
      }

      return $this->redirectToRoute('app_administrateur_tct_import', [
        'entite' => $entite->getId(),
      ]);
    }

    return $this->render('administrateur/transaction/carte/total/import.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
    ]);
  }



  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request, EntityManagerInterface $em): Response
  {
    $t = (new TransactionCarteTotal())->setEntite($entite);

    $form = $this->createForm(TransactionCarteTotalType::class, $t, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->persist($t);
      $em->flush();

      $this->addFlash('success', 'Transaction TOTAL ajoutée.');
      return $this->redirectToRoute('app_administrateur_tct_show', ['entite' => $entite->getId(), 'id' => $t->getId()]);
    }

    return $this->render('administrateur/transaction/carte/total/form.html.twig', [
      'entite' => $entite,
      't' => $t,
      'form' => $form->createView(),
      'modeEdition' => false,
    ]);
  }

  #[Route('/edit/{id}', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, TransactionCarteTotal $t, Request $request, EntityManagerInterface $em): Response
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(TransactionCarteTotalType::class, $t, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->flush();

      $this->addFlash('success', 'Transaction TOTAL mise à jour.');
      return $this->redirectToRoute('app_administrateur_tct_show', ['entite' => $entite->getId(), 'id' => $t->getId()]);
    }

    return $this->render('administrateur/transaction/carte/total/form.html.twig', [
      'entite' => $entite,
      't' => $t,
      'form' => $form->createView(),
      'modeEdition' => true,
    ]);
  }


  #[Route('/export-all', name: 'export_all', methods: ['POST'])]
  public function exportAll(
    Entite $entite,
    Request $request,
    EntityManagerInterface $em
  ): Response {
    $search = trim((string) $request->request->get('search', ''));
    $orderColumn = (int) $request->request->get('orderColumn', 0);
    $orderDir = strtolower((string) $request->request->get('orderDir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $columns = [
      0 => 't.id',
      1 => 't.dateTransaction',
      2 => 't.heureTransaction',
      3 => 't.numeroCarte',
      4 => 't.produit',
      5 => 't.ville',
      6 => 't.montantTtcEur',
    ];

    $sortColumn = $columns[$orderColumn] ?? 't.id';

    $qb = $em->getRepository(\App\Entity\TransactionCarteTotal::class)
      ->createQueryBuilder('t')
      ->andWhere('t.entite = :entite')
      ->setParameter('entite', $entite)
      ->orderBy($sortColumn, $orderDir);

    if ($search !== '') {
      $qb
        ->andWhere('
                t.numeroCarte LIKE :search
                OR t.produit LIKE :search
                OR t.ville LIKE :search
            ')
        ->setParameter('search', '%' . $search . '%');
    }

    $rows = $qb->getQuery()->getResult();

    $response = new StreamedResponse(function () use ($rows) {
      $handle = fopen('php://output', 'w');

      fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 pour Excel

      fputcsv($handle, [
        'ID',
        'Date',
        'Heure',
        'Carte',
        'Produit',
        'Ville',
        'TTC (€)',
      ], ';');

      foreach ($rows as $row) {
        fputcsv($handle, [
          $row->getId(),
          $row->getDateTransaction()?->format('d/m/Y'),
          $row->getHeureTransaction()?->format('H:i'),
          $row->getNumeroCarte(),
          $row->getProduit(),
          $row->getVille(),
          number_format((float) ($row->getMontantTtcEur() ?? 0), 2, ',', ' '),
        ], ';');
      }

      fclose($handle);
    });

    $filename = 'transactions_carte_total_' . date('Ymd_His') . '.csv';

    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }
}
