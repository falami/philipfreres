<?php

namespace App\Controller\Administrateur;

use App\Dto\Carburant\FuelDashboardFilters;
use App\Entity\Entite;
use App\Repository\EnginRepository;
use App\Repository\UtilisateurEntiteRepository;
use App\Service\Carburant\FuelDashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route('/administrateur/{entite}/calendrier', name: 'app_administrateur_calendrier_')]
final class CalendrierController extends AbstractController
{
  public function __construct(
    private readonly FuelDashboardService $fuelDashboardService,
    private readonly EnginRepository $enginRepository,
    private readonly UtilisateurEntiteRepository $utilisateurEntiteRepository,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    $engins = $this->enginRepository->createQueryBuilder('e')
      ->andWhere('e.entite = :entite')
      ->setParameter('entite', $entite)
      ->orderBy('e.nom', 'ASC')
      ->getQuery()
      ->getResult();

    $utilisateurEntites = $this->utilisateurEntiteRepository->createQueryBuilder('ue')
      ->leftJoin('ue.utilisateur', 'u')
      ->addSelect('u')
      ->andWhere('ue.entite = :entite')
      ->andWhere('ue.status = :status')
      ->setParameter('entite', $entite)
      ->setParameter('status', 'active')
      ->orderBy('u.prenom', 'ASC')
      ->addOrderBy('u.nom', 'ASC')
      ->getQuery()
      ->getResult();

    $employes = [];
    foreach ($utilisateurEntites as $ue) {
      $u = $ue->getUtilisateur();
      if ($u) {
        $employes[$u->getId()] = $u;
      }
    }

    return $this->render('administrateur/calendrier/index.html.twig', [
      'entite' => $entite,
      'engins' => $engins,
      'employes' => array_values($employes),
    ]);
  }

  #[Route('/planning-data', name: 'planning_data', methods: ['GET'])]
  public function planningData(Request $request, Entite $entite): JsonResponse
  {
    $filters = FuelDashboardFilters::fromArray($request->query->all());

    return $this->json(
      $this->fuelDashboardService->getPlanningMatrix($entite->getId(), $filters)
    );
  }
}
