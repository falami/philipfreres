<?php

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Mandataire;
use App\Entity\Utilisateur;
use App\Form\Administrateur\MandataireType;
use App\Repository\MandataireRepository;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/mandataire', name: 'app_administrateur_mandataire_')]
#[IsGranted(TenantPermission::CHANTIER_MANAGE, subject: 'entite')]
class MandataireController extends AbstractController
{
  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/mandataire/index.html.twig', [
      'entite' => $entite,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, MandataireRepository $repo): JsonResponse
  {
    $draw = $request->request->getInt('draw', 0);
    $start = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 25);

    $search = trim((string) ($request->request->all('search')['value'] ?? ''));

    $order = (array) $request->request->all('order');
    $col = (int) ($order[0]['column'] ?? 0);
    $dir = strtolower((string) ($order[0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

    $orderMap = [
      0 => 'm.id',
      1 => 'm.societe',
      2 => 'm.nom',
      3 => 'm.email',
      4 => 'm.telephone',
      5 => 'm.ville',
      6 => 'm.actif',
    ];

    $qb = $repo->createListQb($entite, $search)
      ->orderBy($orderMap[$col] ?? 'm.nom', $dir)
      ->setFirstResult($start)
      ->setMaxResults($length);

    $rows = $qb->getQuery()->getResult();

    $recordsTotal = (int) $repo->createQueryBuilder('m')
      ->select('COUNT(m.id)')
      ->andWhere('m.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()
      ->getSingleScalarResult();

    $recordsFiltered = (int) $repo->createListQb($entite, $search)
      ->select('COUNT(m.id)')
      ->getQuery()
      ->getSingleScalarResult();

    $data = [];

    foreach ($rows as $mandataire) {
      $data[] = [
        'id' => $mandataire->getId(),
        'societe' => $mandataire->getSociete() ?: '-',
        'nom' => $mandataire->getNom(),
        'email' => $mandataire->getEmail() ?: '-',
        'telephone' => $mandataire->getTelephone() ?: '-',
        'ville' => $mandataire->getVille() ?: '-',
        'actif' => $mandataire->isActif()
          ? '<span class="badge text-bg-success">Actif</span>'
          : '<span class="badge text-bg-secondary">Inactif</span>',
        'actions' => $this->renderView('administrateur/mandataire/_actions.html.twig', [
          'entite' => $entite,
          'mandataire' => $mandataire,
        ]),
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  #[Route('/ajouter', name: 'ajouter', methods: ['GET', 'POST'])]
  #[Route('/modifier/{id}', name: 'modifier', methods: ['GET', 'POST'])]
  public function addEdit(Entite $entite, Request $request, EntityManagerInterface $em, ?Mandataire $mandataire = null): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $modeEdition = $mandataire !== null;

    if (!$mandataire) {
      $mandataire = new Mandataire();
      $mandataire->setEntite($entite);
      $mandataire->setCreateur($user);
    } elseif ($mandataire->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(MandataireType::class, $mandataire);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->persist($mandataire);
      $em->flush();

      $this->addFlash('success', $modeEdition ? 'Mandataire modifié.' : 'Mandataire ajouté.');

      return $this->redirectToRoute('app_administrateur_mandataire_index', [
        'entite' => $entite->getId(),
      ]);
    }

    return $this->render('administrateur/mandataire/form.html.twig', [
      'entite' => $entite,
      'form' => $form,
      'mandataire' => $mandataire,
      'modeEdition' => $modeEdition,
    ]);
  }

  #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function show(Entite $entite, Mandataire $mandataire): Response
  {
    if ($mandataire->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    return $this->render('administrateur/mandataire/show.html.twig', [
      'entite' => $entite,
      'mandataire' => $mandataire,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
  public function delete(Entite $entite, Mandataire $mandataire, Request $request, EntityManagerInterface $em): RedirectResponse
  {
    if ($mandataire->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('delete_mandataire_' . $mandataire->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_mandataire_index', ['entite' => $entite->getId()]);
    }

    $em->remove($mandataire);
    $em->flush();

    $this->addFlash('success', 'Mandataire supprimé.');

    return $this->redirectToRoute('app_administrateur_mandataire_index', ['entite' => $entite->getId()]);
  }
}
