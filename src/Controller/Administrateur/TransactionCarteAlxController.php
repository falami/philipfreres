<?php
// src/Controller/Administrateur/TransactionCarteAlxController.php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, TransactionCarteAlx};
use App\Form\Administrateur\{TransactionCarteAlxImportType, TransactionCarteAlxType};
use App\Security\Permission\TenantPermission;
use App\Service\Import\TransactionCarteAlxExcelImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/transaction/carte/alx', name: 'app_administrateur_tca_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class TransactionCarteAlxController extends AbstractController
{
  public function __construct(
    private readonly TransactionCarteAlxExcelImporter $importer,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/transaction/carte/alx/index.html.twig', [
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

    $orderMap = [
      0 => 't.id',
      1 => 't.journee',
      2 => 't.horaire',
      3 => 't.vehicule',
      4 => 't.agent',
      5 => 't.quantite',
      6 => 't.prixUnitaire',
      7 => 't.cuve',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 't.id';

    $repo = $em->getRepository(TransactionCarteAlx::class);

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
        t.vehicule LIKE :q
        OR t.agent LIKE :q
        OR t.codeVeh LIKE :q
        OR t.codeAgent LIKE :q
      ')
        ->setParameter('q', '%' . $searchV . '%');
    }

    $qbCount = (clone $qb);
    $qbCount->resetDQLPart('select');
    $qbCount->resetDQLPart('orderBy');

    $recordsFiltered = (int) $qbCount->select('COUNT(DISTINCT t.id)')
      ->getQuery()->getSingleScalarResult();

    /** @var TransactionCarteAlx[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('t.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = array_map(function (TransactionCarteAlx $t) use ($entite) {
      return [
        'id' => $t->getId(),
        'journee' => $t->getJournee()?->format('d/m/Y') ?? '—',
        'horaire' => $t->getHoraire()?->format('H:i') ?? '—',
        'vehicule' => $t->getVehicule() ?: '—',
        'agent' => $t->getAgent() ?: '—',
        'quantite' => $t->getQuantite() ?? '—',
        'prix' => $t->getPrixUnitaire() ?? '—',
        'cuve' => $t->getCuve() ?? '—',
        'actions' => $this->renderView('administrateur/transaction/carte/alx/_actions.html.twig', [
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
  public function show(Entite $entite, TransactionCarteAlx $t): Response
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    return $this->render('administrateur/transaction/carte/alx/show.html.twig', [
      'entite' => $entite,
      't' => $t,
    ]);
  }

  #[Route('/ajouter', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request, EntityManagerInterface $em): Response
  {
    $t = new TransactionCarteAlx();
    $t->setEntite($entite);

    $form = $this->createForm(TransactionCarteAlxType::class, $t);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      // importKey note (si tu ajoutes à la main)
      $t->setImportKey(sha1($entite->getId() . '|note|' . microtime(true)));

      $em->persist($t);
      $em->flush();

      $this->addFlash('success', 'Transaction ALX ajoutée.');
      return $this->redirectToRoute('app_administrateur_tca_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/transaction/carte/alx/form.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
      'modeEdition' => false,
    ]);
  }

  #[Route('/modifier/{id}', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, TransactionCarteAlx $t, Request $request, EntityManagerInterface $em): Response
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(TransactionCarteAlxType::class, $t);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->flush();
      $this->addFlash('success', 'Transaction ALX modifiée.');
      return $this->redirectToRoute('app_administrateur_tca_show', ['entite' => $entite->getId(), 'id' => $t->getId()]);
    }

    return $this->render('administrateur/transaction/carte/alx/form.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
      'modeEdition' => true,
      't' => $t,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
  public function delete(Entite $entite, TransactionCarteAlx $t, Request $request, EntityManagerInterface $em): RedirectResponse
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $token = (string)$request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('delete_tca_' . $t->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_tca_index', ['entite' => $entite->getId()]);
    }

    $id = $t->getId();
    $em->remove($t);
    $em->flush();

    $this->addFlash('success', 'Transaction ALX #' . $id . ' supprimée.');
    return $this->redirectToRoute('app_administrateur_tca_index', ['entite' => $entite->getId()]);
  }

  #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
  public function import(Entite $entite, Request $request): Response
  {
    $form = $this->createForm(TransactionCarteAlxImportType::class);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      /** @var UploadedFile|null $file */
      $file = $form->get('file')->getData();

      if (!$file instanceof UploadedFile) {
        $this->addFlash('danger', 'Aucun fichier reçu.');
        return $this->redirectToRoute('app_administrateur_tca_import', ['entite' => $entite->getId()]);
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

      return $this->redirectToRoute('app_administrateur_tca_import', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/transaction/carte/alx/import.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
    ]);
  }
}
