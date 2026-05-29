<?php

namespace App\Controller\Administrateur;

use App\Entity\{Engin, EnginUsageReleve, Entite, Utilisateur};
use App\Form\Administrateur\EnginUsageReleveType;
use App\Repository\EnginUsageReleveRepository;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\Engin\EnginUsageDashboardService;

#[Route('/administrateur/{entite}/engin-utilisation', name: 'app_administrateur_engin_usage_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class EnginUsageController extends AbstractController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly EnginUsageReleveRepository $repo,
    private readonly EnginUsageDashboardService $dashboardService,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, Request $request): Response
  {
    $year = (int) date('Y');

    return $this->render('administrateur/engin_usage/index.html.twig', [
      'entite' => $entite,
      'engins' => $this->em->getRepository(Engin::class)->findBy(['entite' => $entite], ['nom' => 'ASC']),
      'selectedEnginId' => $this->safeInt($request->query->get('enginId')),
      'start' => "$year-01-01",
      'end' => "$year-12-31",
    ]);
  }

  #[Route('/ajouter', name: 'ajouter', methods: ['GET', 'POST'])]
  public function ajouter(Entite $entite, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $releve = new EnginUsageReleve();
    $releve->setEntite($entite);
    $releve->setCreateur($user);

    $enginId = $this->safeInt($request->query->get('enginId'));

    if ($enginId) {
      $engin = $this->em->getRepository(Engin::class)->find($enginId);

      if ($engin && $engin->getEntite()?->getId() === $entite->getId()) {
        $releve->setEngin($engin);
      }
    }

    $form = $this->createForm(EnginUsageReleveType::class, $releve, [
      'entite' => $entite,
    ]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      if ($releve->getEngin()?->getEntite()?->getId() !== $entite->getId()) {
        throw $this->createNotFoundException();
      }

      $releve->setEntite($entite);
      $releve->setCreateur($user);

      $this->em->persist($releve);
      $this->em->flush();

      $this->addFlash('success', 'Relevé ajouté avec succès.');

      return $this->redirectToRoute('app_administrateur_engin_usage_index', [
        'entite' => $entite->getId(),
        'enginId' => $releve->getEngin()?->getId(),
      ]);
    }

    return $this->render('administrateur/engin_usage/form.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
      'releve' => $releve,
      'modeEdition' => false,
      'engins' => $this->em->getRepository(Engin::class)->findBy(['entite' => $entite], ['nom' => 'ASC']),
    ]);
  }

  #[Route('/dt', name: 'dt', methods: ['GET'])]
  public function dt(Entite $entite, Request $request): JsonResponse
  {
    $draw = (int) $request->query->get('draw', 0);
    $start = max(0, (int) $request->query->get('start', 0));
    $length = (int) $request->query->get('length', 25);

    if ($length <= 0 || $length > 200) {
      $length = 25;
    }

    $search = trim((string) ($request->query->all('search')['value'] ?? ''));
    $filters = $this->filters($request);

    $order = $request->query->all('order')[0] ?? [];

    $orderColumn = isset($order['column']) ? (int) $order['column'] : 2;
    $orderDir = strtoupper((string) ($order['dir'] ?? 'DESC'));

    [$rows, $total, $filtered] = $this->repo->fetchDtRows(
      $entite,
      $filters,
      $start,
      $length,
      $search,
      $orderColumn,
      $orderDir
    );

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $total,
      'recordsFiltered' => $filtered,
      'data' => array_map(function (array $r) use ($entite) {
        $type = $this->normalizeCompteurType($r['compteurType'] ?? null);
        $unit = $type === 'kilometre' ? 'km' : 'h';

        return [
          'id' => $r['id'],
          'date' => $r['dateReleve'] instanceof \DateTimeInterface ? $r['dateReleve']->format('d/m/Y') : '-',
          'engin' => $r['enginNom'] ?? '-',
          'type' => $type === 'kilometre' ? 'Km' : 'H',
          'valeur' => number_format((float) $r['valeur'], 2, ',', ' ') . ' ' . $unit,

          'previousDate' => $r['previousDateReleve'] instanceof \DateTimeInterface
            ? $r['previousDateReleve']->format('d/m/Y')
            : '-',

          'ecartDays' => $r['previousDateReleve'] instanceof \DateTimeInterface && $r['dateReleve'] instanceof \DateTimeInterface
            ? abs($r['previousDateReleve']->diff($r['dateReleve'])->days) . ' jour' . (abs($r['previousDateReleve']->diff($r['dateReleve'])->days) > 1 ? 's' : '')
            : '-',

          'daysSinceLast' => $r['daysSinceLast'] !== null
            ? $r['daysSinceLast'] . ' jour' . ((int) $r['daysSinceLast'] > 1 ? 's' : '')
            : '-',

          'ecart' => $r['ecartValeur'] !== null
            ? number_format((float) $r['ecartValeur'], 2, ',', ' ') . ' ' . $unit
            : '-',

          'isLate' => (bool) ($r['isLate'] ?? false),

          'consommation' => $r['consommation'] !== null
            ? number_format((float) $r['consommation'], 2, ',', ' ') . ' ' . (
              $type === 'kilometre' ? 'l/100 km' : 'l/h'
            )
            : '-',

          'actions' => $this->renderView('administrateur/engin_usage/_actions.html.twig', [
            'entite' => $entite,
            'releveId' => $r['id'],
          ]),
        ];
      }, $rows),
    ]);
  }

  #[Route('/api/summary', name: 'api_summary', methods: ['GET'])]
  public function summary(Entite $entite, Request $request): JsonResponse
  {
    return new JsonResponse($this->repo->dashboardSummary($entite, $this->filters($request)));
  }

  #[Route('/supprimer/{id}', name: 'delete', methods: ['POST'])]
  public function delete(Entite $entite, EnginUsageReleve $releve, Request $request): RedirectResponse
  {
    if ($releve->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('delete_usage_' . $releve->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_engin_usage_index', ['entite' => $entite->getId()]);
    }

    $this->em->remove($releve);
    $this->em->flush();

    $this->addFlash('success', 'Relevé supprimé.');

    return $this->redirectToRoute('app_administrateur_engin_usage_index', ['entite' => $entite->getId()]);
  }

  private function filters(Request $request): array
  {
    return [
      'dateStart' => $request->query->get('dateStart') ?: date('Y-01-01'),
      'dateEnd' => $request->query->get('dateEnd') ?: date('Y-12-31'),
      'enginId' => $this->safeInt($request->query->get('enginId')),
    ];
  }

  private function safeInt(mixed $value): ?int
  {
    return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
  }

  private function normalizeCompteurType(mixed $value): string
  {
    if ($value instanceof \BackedEnum) {
      return $value->value;
    }

    return (string) $value;
  }

  #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
  public function dashboard(Entite $entite, Request $request): Response
  {
    $year = (int) date('Y');

    return $this->render('administrateur/engin_usage/dashboard.html.twig', [
      'entite' => $entite,
      'engins' => $this->em->getRepository(Engin::class)->findBy(['entite' => $entite], ['nom' => 'ASC']),
      'selectedEnginId' => $this->safeInt($request->query->get('enginId')),
      'start' => "$year-01-01",
      'end' => "$year-12-31",
    ]);
  }

  #[Route('/api/dashboard', name: 'api_dashboard', methods: ['GET'])]
  public function apiDashboard(Entite $entite, Request $request): JsonResponse
  {
    return new JsonResponse(
      $this->dashboardService->build($entite, $this->filters($request))
    );
  }
}
