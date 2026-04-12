<?php
// src/Controller/Administrateur/FuelCalendarController.php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Engin, Utilisateur, UtilisateurEntite};
use App\Repository\FuelDashboardRepository;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/carburant/calendrier', name: 'app_administrateur_fuel_calendar_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class FuelCalendarController extends AbstractController
{
  public function __construct(
    private readonly FuelDashboardRepository $repo,
    private readonly EntityManagerInterface $em,
  ) {}

  // =========================
  // PAGES (3)
  // =========================

  #[Route('', name: 'global', methods: ['GET'])]
  public function global(Entite $entite): Response
  {
    [$start, $end] = $this->defaultYearRange();

    return $this->render('administrateur/carburant/calendrier_global.html.twig', [
      'entite' => $entite,
      'start' => $start,
      'end' => $end,
      'engins' => $this->loadEngins($entite),
      'employes' => $this->loadEmployes($entite),
    ]);
  }


  #[Route('/engin', name: 'engin', methods: ['GET'])]
  public function byEngin(Entite $entite): Response
  {
    [$start, $end] = $this->defaultYearRange();

    return $this->render('administrateur/carburant/calendrier_engin.html.twig', [
      'entite' => $entite,
      'start' => $start,
      'end' => $end,
      'engins' => $this->loadEngins($entite),
      'employes' => $this->loadEmployes($entite),
    ]);
  }

  #[Route('/employe', name: 'employe', methods: ['GET'])]
  public function byEmploye(Entite $entite): Response
  {
    [$start, $end] = $this->defaultYearRange();

    return $this->render('administrateur/carburant/calendrier_employe.html.twig', [
      'entite' => $entite,
      'start' => $start,
      'end' => $end,
      'engins' => $this->loadEngins($entite),
      'employes' => $this->loadEmployes($entite),
    ]);
  }

  // =========================
  // API (FullCalendar + Modal)
  // =========================

  /**
   * FullCalendar appelle ?start=...&end=...
   * On renvoie:
   * - events: [{id, title, start, allDay, extendedProps:{...}}]
   * - totals: {amount_cents, cnt, qty}
   */
  #[Route('/api/calendar/events', name: 'api_events', methods: ['GET'])]
  public function apiEvents(Entite $entite, Request $req): JsonResponse
  {
    $f = $this->filters($req);

    // Range FullCalendar (prioritaire, sinon filtres)
    $fcStart = $this->parseAnyDate($req->query->get('start')) ?: new \DateTimeImmutable($f['dateStart']);
    $fcEnd   = $this->parseAnyDate($req->query->get('end'))   ?: new \DateTimeImmutable($f['dateEnd']);

    // FullCalendar donne end exclusive -> on garde tel quel, mais on veut un BETWEEN inclusif
    // donc on retranche 1 seconde à end si besoin.
    $endInclusive = $fcEnd->modify('-1 second');

    $scope = $this->scope($req); // ['mode'=>'global|engin|employe', 'enginId'=>?, 'employeId'=>?]


    // ✅ Si la page est "engin" ou "employe" et qu'on n'a pas d'ID -> on renvoie vide
    if ($scope['mode'] === 'engin' && !$scope['enginId']) {
      return new JsonResponse([
        'events' => [],
        'totals' => ['amount_cents' => 0, 'cnt' => 0, 'qty' => 0.0],
        'scope' => $scope,
      ]);
    }
    if ($scope['mode'] === 'employe' && !$scope['employeId']) {
      return new JsonResponse([
        'events' => [],
        'totals' => ['amount_cents' => 0, 'cnt' => 0, 'qty' => 0.0],
        'scope' => $scope,
      ]);
    }


    $rows = $this->repo->calendarDaily(
      $entite,
      $f,
      $fcStart->format('Y-m-d 00:00:00'),
      $endInclusive->format('Y-m-d 23:59:59'),
      $scope['enginId'],
      $scope['employeId'],
    );

    // Totals (badges)
    $totals = $this->repo->calendarTotals(
      $entite,
      $f,
      $fcStart->format('Y-m-d 00:00:00'),
      $endInclusive->format('Y-m-d 23:59:59'),
      $scope['enginId'],
      $scope['employeId'],
    );

    // Events (1 event / jour)
    $events = array_map(static function (array $r): array {
      $day = (string)($r['day'] ?? '');
      $amount = (int)($r['amount_cents'] ?? 0);
      $cnt = (int)($r['cnt'] ?? 0);
      $qty = (float)($r['qty'] ?? 0);

      return [
        'id' => $day,
        'title' => sprintf('%s · %d tx', $amount, $cnt), // côté JS on formatte en €
        'start' => $day,
        'allDay' => true,
        'extendedProps' => [
          'day' => $day,
          'amount_cents' => $amount,
          'cnt' => $cnt,
          'qty' => $qty,
          // breakdown json (string) -> JS parse si besoin
          'byProvider' => $r['by_provider_json'] ?? '[]',
        ],
      ];
    }, $rows);

    return new JsonResponse([
      'events' => $events,
      'totals' => [
        'amount_cents' => (int)($totals['amount_cents'] ?? 0),
        'cnt' => (int)($totals['cnt'] ?? 0),
        'qty' => (float)($totals['qty'] ?? 0),
      ],
    ]);
  }

  /**
   * Modal details d'un jour
   * GET: day=YYYY-MM-DD (obligatoire)
   */
  #[Route('/api/calendar/day-details', name: 'api_day_details', methods: ['GET'])]
  public function apiDayDetails(Entite $entite, Request $req): JsonResponse
  {
    $f = $this->filters($req);

    $day = trim((string)$req->query->get('day', ''));
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $day);
    if (!$d) {
      return new JsonResponse(['ok' => false, 'error' => 'Invalid day'], 400);
    }

    $scope = $this->scope($req);

    if ($scope['mode'] === 'engin' && !$scope['enginId']) {
      return new JsonResponse(['ok' => false, 'error' => 'No engin selected'], 400);
    }
    if ($scope['mode'] === 'employe' && !$scope['employeId']) {
      return new JsonResponse(['ok' => false, 'error' => 'No employe selected'], 400);
    }

    $details = $this->repo->calendarDayDetails(
      $entite,
      $f,
      $day . ' 00:00:00',
      $day . ' 23:59:59',
      $scope['enginId'],
      $scope['employeId'],
      220 // limite lignes (robuste)
    );

    return new JsonResponse([
      'ok' => true,
      'day' => $day,
      'scope' => $scope,
      'summary' => $details['summary'],
      'byProvider' => $details['byProvider'],
      'rows' => $details['rows'],
    ]);
  }

  // =========================
  // Helpers
  // =========================

  private function scope(Request $req): array
  {
    // mode défini par la page:
    // - global => pas de scope
    // - engin  => enginId obligatoire côté UI (mais on tolère vide)
    // - employe => employeId obligatoire côté UI (mais on tolère vide)
    $mode = trim((string)$req->query->get('mode', 'global'));
    if (!in_array($mode, ['global', 'engin', 'employe'], true)) $mode = 'global';

    $enginId = (int)$req->query->get('scopeEnginId', 0);
    $employeId = (int)$req->query->get('scopeEmployeId', 0);

    return [
      'mode' => $mode,
      'enginId' => $enginId > 0 ? $enginId : null,
      'employeId' => $employeId > 0 ? $employeId : null,
    ];
  }

  private function defaultYearRange(): array
  {
    $y = (int)(new \DateTimeImmutable())->format('Y');
    $start = (new \DateTimeImmutable("$y-01-01"))->format('Y-m-d');
    $end   = (new \DateTimeImmutable("$y-12-31"))->format('Y-m-d');
    return [$start, $end];
  }

  private function loadEngins(Entite $entite): array
  {
    return $this->em->getRepository(Engin::class)->findBy(
      ['entite' => $entite],
      ['nom' => 'ASC']
    );
  }

  private function loadEmployes(Entite $entite): array
  {
    // identique à ton dashboard (TENANT_ADMIN inclus)
    return $this->em->createQueryBuilder()
      ->select('u')
      ->from(Utilisateur::class, 'u')
      ->innerJoin(UtilisateurEntite::class, 'ue', 'WITH', 'ue.utilisateur = u')
      ->andWhere('ue.entite = :entite')
      ->setParameter('entite', $entite)
      ->orderBy('u.nom', 'ASC')
      ->addOrderBy('u.prenom', 'ASC')
      ->getQuery()
      ->getResult();
  }

  private function parseAnyDate(?string $s): ?\DateTimeImmutable
  {
    $s = trim((string)$s);
    if ($s === '') return null;

    // ISO / RFC
    try {
      return new \DateTimeImmutable($s);
    } catch (\Throwable) {
    }

    // Y-m-d
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
    if ($d instanceof \DateTimeImmutable) return $d;

    // d/m/Y
    $d = \DateTimeImmutable::createFromFormat('d/m/Y', $s);
    if ($d instanceof \DateTimeImmutable) return $d;

    return null;
  }

  /**
   * Reprend ta logique de FuelDashboardController::filters()
   * (copiée volontairement -> robuste, sans dépendance)
   */
  private function filters(Request $req): array
  {
    $parse = function (?string $s, string $fallback): string {
      $s = trim((string) $s);
      if ($s === '') return (new \DateTimeImmutable($fallback))->format('Y-m-d');

      $d = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
      if ($d instanceof \DateTimeImmutable) return $d->format('Y-m-d');

      $d = \DateTimeImmutable::createFromFormat('d/m/Y', $s);
      if ($d instanceof \DateTimeImmutable) return $d->format('Y-m-d');

      try {
        return (new \DateTimeImmutable($s))->format('Y-m-d');
      } catch (\Throwable) {
        return (new \DateTimeImmutable($fallback))->format('Y-m-d');
      }
    };

    $ds = $parse($req->query->get('dateStart'), 'first day of january');
    $de = $parse($req->query->get('dateEnd'), 'last day of december');

    $providers = $req->query->all('providers');
    if (!is_array($providers) || $providers === []) $providers = ['ALX', 'TOTAL', 'EDENRED'];
    $providers = array_values(array_intersect(['ALX', 'TOTAL', 'EDENRED'], $providers));
    if ($providers === []) $providers = ['ALX', 'TOTAL', 'EDENRED'];

    $label = trim((string) $req->query->get('label', ''));
    if (mb_strlen($label) > 120) $label = mb_substr($label, 0, 120);

    $enginIds = $req->query->all('enginIds');
    $enginIds = is_array($enginIds) ? array_values(array_filter(array_map('intval', $enginIds))) : [];

    $employeIds = $req->query->all('employeIds');
    $employeIds = is_array($employeIds) ? array_values(array_filter(array_map('intval', $employeIds))) : [];

    return [
      'dateStart' => $ds,
      'dateEnd' => $de . ' 23:59:59',
      'providers' => $providers,
      'enginIds' => $enginIds,
      'employeIds' => $employeIds,
      'label' => $label,
    ];
  }
}
