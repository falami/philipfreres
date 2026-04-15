<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Materiel, Utilisateur};
use App\Form\Administrateur\MaterielType;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/materiel', name: 'app_administrateur_materiel_')]
#[IsGranted(TenantPermission::MATERIEL_MANAGE, subject: 'entite')]
final class MaterielController extends AbstractController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/materiel/index.html.twig', [
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

    $categorieFilter = trim((string) $request->request->get('categorieFilter', ''));
    $searchName = trim((string) $request->request->get('searchName', ''));

    $order       = (array) $request->request->all('order');
    $orderDir    = strtolower((string) ($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderColIdx = (int) ($order[0]['column'] ?? 0);

    $orderMap = [
      0 => 'm.id',
      2 => 'm.nom',
      3 => 'm.categorie',
      4 => 'm.numeroSerie',
      5 => 'm.statut',
      6 => 'm.dateCreation',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 'm.id';

    $applyFilters = function (QueryBuilder $qb, string $alias) use ($searchV, $categorieFilter, $searchName): void {
      if ($searchV !== '') {
        $qb->andWhere("(
                    $alias.nom LIKE :dt_q
                    OR $alias.numeroSerie LIKE :dt_q
                    OR $alias.categorie LIKE :dt_q
                    OR $alias.statut LIKE :dt_q
                )")
          ->setParameter('dt_q', '%' . $searchV . '%');
      }

      if ($searchName !== '') {
        $qb->andWhere("(
                    $alias.nom LIKE :fb_q
                    OR $alias.numeroSerie LIKE :fb_q
                    OR $alias.categorie LIKE :fb_q
                    OR $alias.statut LIKE :fb_q
                )")
          ->setParameter('fb_q', '%' . $searchName . '%');
      }

      if ($categorieFilter !== '') {
        $qb->andWhere("$alias.categorie = :categorieFilter")
          ->setParameter('categorieFilter', $categorieFilter);
      }
    };

    $qb = $this->em->createQueryBuilder()
      ->select('m', 'c')
      ->from(Materiel::class, 'm')
      ->leftJoin('m.createur', 'c')
      ->andWhere('m.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qb, 'm');

    $qbTotal = $this->em->createQueryBuilder()
      ->select('COUNT(m_t.id)')
      ->from(Materiel::class, 'm_t')
      ->andWhere('m_t.entite = :entite')
      ->setParameter('entite', $entite);

    $recordsTotal = (int) $qbTotal->getQuery()->getSingleScalarResult();

    $qbFiltered = $this->em->createQueryBuilder()
      ->select('COUNT(m_f.id)')
      ->from(Materiel::class, 'm_f')
      ->andWhere('m_f.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qbFiltered, 'm_f');
    $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();

    /** @var Materiel[] $materiels */
    $materiels = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('m.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();

    $data = [];
    foreach ($materiels as $materiel) {
      $createur = $materiel->getCreateur();
      $createurNom = $createur
        ? trim(($createur->getPrenom() ?? '') . ' ' . strtoupper($createur->getNom() ?? ''))
        : '—';

      $data[] = [
        'id'           => $materiel->getId(),
        'photo'        => $this->renderView('administrateur/materiel/_photo_cell.html.twig', [
          'materiel' => $materiel,
        ]),
        'nom'          => $materiel->getNom() ?: '—',
        'categorie'    => $materiel->getCategorie()?->label() ?? '—',
        'numeroSerie'  => $materiel->getNumeroSerie() ?: '—',
        'statut'       => $materiel->getStatut()->label(),
        'statutBadge'  => $materiel->getStatut()->badgeClass(),
        'dateCreation' => $materiel->getDateCreation()?->format('d/m/Y H:i') ?? '—',
        'createur'     => $createurNom,
        'actions'      => $this->renderView('administrateur/materiel/_actions.html.twig', [
          'entite'   => $entite,
          'materiel' => $materiel,
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
  public function addEdit(Entite $entite, Request $request, ?Materiel $materiel = null): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $isEdit = (bool) $materiel;

    if (!$materiel) {
      $materiel = new Materiel();
      $materiel->setEntite($entite);
      $materiel->setCreateur($user);
    } else {
      $this->assertMaterielInEntite($entite, $materiel);
    }

    $existingPhoto = $materiel->getPhotoCouverture();

    $form = $this->createForm(MaterielType::class, $materiel, [
      'is_edit' => $isEdit,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      /** @var UploadedFile|null $photoFile */
      $photoFile = $form->get('photo')->getData();

      if ($photoFile instanceof UploadedFile) {
        $newName = bin2hex(random_bytes(8)) . '.' . ($photoFile->guessExtension() ?: 'jpg');
        $targetDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/photos/materiel';

        if (!is_dir($targetDirectory)) {
          @mkdir($targetDirectory, 0775, true);
        }

        $photoFile->move($targetDirectory, $newName);
        $materiel->setPhotoCouverture($newName);

        if ($existingPhoto) {
          $oldPath = $targetDirectory . '/' . $existingPhoto;
          if (is_file($oldPath)) {
            @unlink($oldPath);
          }
        }
      }

      $this->em->persist($materiel);
      $this->em->flush();

      $this->addFlash('success', $isEdit ? 'Matériel modifié.' : 'Matériel ajouté.');

      return $this->redirectToRoute('app_administrateur_materiel_index', [
        'entite' => $entite->getId(),
      ]);
    }

    return $this->render('administrateur/materiel/form.html.twig', [
      'entite'      => $entite,
      'materiel'    => $materiel,
      'modeEdition' => $isEdit,
      'form'        => $form->createView(),
    ]);
  }

  #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function show(Entite $entite, Materiel $materiel): Response
  {
    $this->assertMaterielInEntite($entite, $materiel);

    return $this->render('administrateur/materiel/show.html.twig', [
      'entite'   => $entite,
      'materiel' => $materiel,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function delete(Entite $entite, Materiel $materiel, Request $request): RedirectResponse
  {
    $this->assertMaterielInEntite($entite, $materiel);

    if (!$this->isCsrfTokenValid('delete_materiel_' . $materiel->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');

      return $this->redirectToRoute('app_administrateur_materiel_index', [
        'entite' => $entite->getId(),
      ]);
    }

    $photo = $materiel->getPhotoCouverture();

    $this->em->remove($materiel);
    $this->em->flush();

    if ($photo) {
      $path = $this->getParameter('kernel.project_dir') . '/public/uploads/photos/materiel/' . $photo;
      if (is_file($path)) {
        @unlink($path);
      }
    }

    $this->addFlash('success', 'Matériel supprimé.');

    return $this->redirectToRoute('app_administrateur_materiel_index', [
      'entite' => $entite->getId(),
    ]);
  }

  private function assertMaterielInEntite(Entite $entite, Materiel $materiel): void
  {
    if ($materiel->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException('Matériel introuvable pour cette entité.');
    }
  }
}
