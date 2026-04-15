<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Dechet, Entite, Utilisateur};
use App\Form\Administrateur\DechetType;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/dechet-type', name: 'app_administrateur_dechet_')]
#[IsGranted(TenantPermission::CHANTIER_MANAGE, subject: 'entite')]
final class DechetController extends AbstractController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/dechet/index.html.twig', [
      'entite' => $entite,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request): JsonResponse
  {
    $draw   = $request->request->getInt('draw', 0);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 25);

    if ($length <= 0 || $length > 500) {
      $length = 25;
    }

    $search = (array) $request->request->all('search');
    $searchV = trim((string) ($search['value'] ?? ''));

    $uniteFilter = trim((string) $request->request->get('uniteFilter', ''));
    $searchName  = trim((string) $request->request->get('searchName', ''));

    $order       = (array) $request->request->all('order');
    $orderDir    = strtolower((string) ($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderColIdx = (int) ($order[0]['column'] ?? 0);

    $orderMap = [
      0 => 'd.id',
      1 => 'd.nom',
      2 => 'd.unite',
      3 => 'd.dateCreation',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 'd.id';

    $applyFilters = function (QueryBuilder $qb, string $alias) use ($searchV, $uniteFilter, $searchName): void {
      if ($searchV !== '') {
        $qb->andWhere("($alias.nom LIKE :dt_q OR $alias.unite LIKE :dt_q)")
          ->setParameter('dt_q', '%' . $searchV . '%');
      }

      if ($searchName !== '') {
        $qb->andWhere("($alias.nom LIKE :fb_q OR $alias.unite LIKE :fb_q)")
          ->setParameter('fb_q', '%' . $searchName . '%');
      }

      if ($uniteFilter !== '') {
        $qb->andWhere("$alias.unite = :uniteFilter")
          ->setParameter('uniteFilter', $uniteFilter);
      }
    };

    $qb = $this->em->createQueryBuilder()
      ->select('d', 'c')
      ->from(Dechet::class, 'd')
      ->leftJoin('d.createur', 'c')
      ->andWhere('d.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qb, 'd');

    $qbTotal = $this->em->createQueryBuilder()
      ->select('COUNT(d_t.id)')
      ->from(Dechet::class, 'd_t')
      ->andWhere('d_t.entite = :entite')
      ->setParameter('entite', $entite);

    $recordsTotal = (int) $qbTotal->getQuery()->getSingleScalarResult();

    $qbFiltered = $this->em->createQueryBuilder()
      ->select('COUNT(d_f.id)')
      ->from(Dechet::class, 'd_f')
      ->andWhere('d_f.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qbFiltered, 'd_f');
    $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();

    /** @var Dechet[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('d.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();

    $data = [];
    foreach ($rows as $dechet) {
      $data[] = [
        'id'           => $dechet->getId(),
        'nom'          => $dechet->getNom() ?: '—',
        'unite'        => $dechet->getUnite() ?: '—',
        'dateCreation' => $dechet->getDateCreation()?->format('d/m/Y H:i') ?? '—',
        'createur'     => $dechet->getCreateur()
          ? trim(($dechet->getCreateur()?->getPrenom() ?? '') . ' ' . strtoupper($dechet->getCreateur()?->getNom() ?? ''))
          : '—',
        'actions'      => $this->renderView('administrateur/dechet/_actions.html.twig', [
          'entite'     => $entite,
          'dechet' => $dechet,
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
  #[Route('/modifier/{id}', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
  public function addEdit(Entite $entite, Request $request, ?Dechet $dechet = null): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $isEdit = (bool) $dechet;

    if (!$dechet) {
      $dechet = new Dechet();
      $dechet->setEntite($entite);
      $dechet->setCreateur($user);
    } else {
      $this->assertDechetInEntite($entite, $dechet);
    }

    $form = $this->createForm(DechetType::class, $dechet);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->em->persist($dechet);
      $this->em->flush();

      $this->addFlash('success', $isEdit ? 'Type de déchet modifié.' : 'Type de déchet ajouté.');

      return $this->redirectToRoute('app_administrateur_dechet_index', [
        'entite' => $entite->getId(),
      ]);
    }

    return $this->render('administrateur/dechet/form.html.twig', [
      'entite'      => $entite,
      'dechet'      => $dechet,
      'modeEdition' => $isEdit,
      'form'        => $form->createView(),
    ]);
  }

  #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function show(Entite $entite, Dechet $dechet): Response
  {
    $this->assertDechetInEntite($entite, $dechet);

    return $this->render('administrateur/dechet/show.html.twig', [
      'entite'     => $entite,
      'dechet' => $dechet,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function delete(Entite $entite, Dechet $dechet, Request $request): RedirectResponse
  {
    $this->assertDechetInEntite($entite, $dechet);

    if (!$this->isCsrfTokenValid('delete_dechet_type_' . $dechet->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');

      return $this->redirectToRoute('app_administrateur_dechet_index', [
        'entite' => $entite->getId(),
      ]);
    }

    $this->em->remove($dechet);
    $this->em->flush();

    $this->addFlash('success', 'Type de déchet supprimé.');

    return $this->redirectToRoute('app_administrateur_dechet_index', [
      'entite' => $entite->getId(),
    ]);
  }

  private function assertDechetInEntite(Entite $entite, Dechet $dechet): void
  {
    if ($dechet->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException('Type de déchet introuvable pour cette entité.');
    }
  }
}
