<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Note};
use App\Form\Administrateur\NoteType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/notes', name: 'app_administrateur_note_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class NoteController extends AbstractController
{
  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/note/index.html.twig', [
      'entite' => $entite,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
  {
    $start = $request->request->getInt('start', 0);
    $length = $request->request->getInt('length', 25);

    $search = $request->request->all('search');
    $searchV = trim((string)($search['value'] ?? ''));

    $order = $request->request->all('order');
    $orderColIdx = isset($order[0]['column']) ? (int) $order[0]['column'] : 0;
    $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $orderMap = [
      0 => 'n.id',
      1 => 'n.dateTransaction',
      2 => 'n.libelle',
      3 => 'n.quantite',
      4 => 'n.montantTtcEur',
      5 => 'e.nom',
      6 => 'u.nom',
      7 => 'p.id',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 'n.id';

    $repo = $em->getRepository(Note::class);

    $recordsTotal = (int) $repo->createQueryBuilder('n')
      ->select('COUNT(n.id)')
      ->andWhere('n.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()
      ->getSingleScalarResult();

    $qb = $repo->createQueryBuilder('n')
      ->leftJoin('n.engin', 'e')->addSelect('e')
      ->leftJoin('n.utilisateur', 'u')->addSelect('u')
      ->leftJoin('n.produit', 'p')->addSelect('p')
      ->andWhere('n.entite = :entite')
      ->setParameter('entite', $entite);

    if ($searchV !== '') {
      $qb->andWhere('
                n.libelle LIKE :q
                OR n.commentaire LIKE :q
                OR e.nom LIKE :q
                OR e.immatriculation LIKE :q
                OR u.nom LIKE :q
                OR u.prenom LIKE :q
                OR p.categorieProduit LIKE :q
                OR p.sousCategorieProduit LIKE :q
            ')
        ->setParameter('q', '%' . $searchV . '%');
    }

    $qbCount = clone $qb;
    $qbCount->resetDQLPart('select');
    $qbCount->resetDQLPart('orderBy');
    $recordsFiltered = (int) $qbCount
      ->select('COUNT(DISTINCT n.id)')
      ->getQuery()
      ->getSingleScalarResult();

    /** @var Note[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('n.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();

    $fmtMoney = static function (?string $value): string {
      if ($value === null || $value === '') {
        return '—';
      }

      return number_format((float) $value, 2, ',', ' ');
    };

    $data = array_map(function (Note $note) use ($entite, $fmtMoney) {
      $engin = $note->getEngin()
        ? trim(
          ($note->getEngin()->getNom() ?? ('Engin #' . $note->getEngin()->getId()))
            . ($note->getEngin()->getImmatriculation() ? ' — ' . $note->getEngin()->getImmatriculation() : '')
        )
        : '—';

      $utilisateur = $note->getUtilisateur()
        ? trim(($note->getUtilisateur()->getPrenom() ?? '') . ' ' . ($note->getUtilisateur()->getNom() ?? ''))
        : '—';

      $produit = $note->getProduit()
        ? $note->getProduit()->getCategorieProduit()->label() . ' — ' . $note->getProduit()->getSousCategorieProduit()->label()
        : '—';

      return [
        'id' => $note->getId(),
        'date' => $note->getDateTransaction()?->format('d/m/Y') ?? '—',
        'libelle' => $note->getLibelle() ?: '—',
        'quantite' => $note->getQuantite() ?? '—',
        'ttc' => $fmtMoney($note->getMontantTtcEur()),
        'engin' => $engin,
        'utilisateur' => $utilisateur ?: '—',
        'produit' => $produit,
        'actions' => $this->renderView('administrateur/note/_actions.html.twig', [
          'note' => $note,
          'entite' => $entite,
        ]),
      ];
    }, $rows);

    return new JsonResponse([
      'draw' => (int) $request->request->get('draw'),
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request, EntityManagerInterface $em): Response
  {
    $note = (new Note())->setEntite($entite);

    $form = $this->createForm(NoteType::class, $note, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->persist($note);
      $em->flush();

      $this->addFlash('success', 'Note ajoutée avec succès.');

      return $this->redirectToRoute('app_administrateur_note_show', [
        'entite' => $entite->getId(),
        'id' => $note->getId(),
      ]);
    }

    return $this->render('administrateur/note/form.html.twig', [
      'entite' => $entite,
      'note' => $note,
      'form' => $form->createView(),
      'modeEdition' => false,
    ]);
  }

  #[Route('/show/{id}', name: 'show', methods: ['GET'])]
  public function show(Entite $entite, Note $note): Response
  {
    if ($note->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    return $this->render('administrateur/note/show.html.twig', [
      'entite' => $entite,
      'note' => $note,
    ]);
  }

  #[Route('/edit/{id}', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, Note $note, Request $request, EntityManagerInterface $em): Response
  {
    if ($note->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(NoteType::class, $note, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->flush();

      $this->addFlash('success', 'Note mise à jour avec succès.');

      return $this->redirectToRoute('app_administrateur_note_show', [
        'entite' => $entite->getId(),
        'id' => $note->getId(),
      ]);
    }

    return $this->render('administrateur/note/form.html.twig', [
      'entite' => $entite,
      'note' => $note,
      'form' => $form->createView(),
      'modeEdition' => true,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
  public function delete(Entite $entite, Note $note, Request $request, EntityManagerInterface $em): RedirectResponse
  {
    if ($note->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $token = (string) $request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('delete_note_' . $note->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');

      return $this->redirectToRoute('app_administrateur_note_index', [
        'entite' => $entite->getId(),
      ]);
    }

    $id = $note->getId();

    $em->remove($note);
    $em->flush();

    $this->addFlash('success', 'Note #' . $id . ' supprimée.');

    return $this->redirectToRoute('app_administrateur_note_index', [
      'entite' => $entite->getId(),
    ]);
  }
}
