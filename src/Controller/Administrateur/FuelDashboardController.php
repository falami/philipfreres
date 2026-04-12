<?php
// src/Controller/Administrateur/FuelDashboardController.php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Engin, Utilisateur, UtilisateurEntite};
use App\Repository\FuelDashboardRepository;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Enum\CategorieProduit;
use App\Enum\SousCategorieProduit;

#[Route('/administrateur/{entite}/carburant', name: 'app_administrateur_fuel_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class FuelDashboardController extends AbstractController
{
  public function __construct(
    private readonly FuelDashboardRepository $repo,
    private readonly EntityManagerInterface $em,
  ) {}

  #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
  public function dashboard(Entite $entite): Response
  {
    $y = (int) (new \DateTimeImmutable())->format('Y');
    $start = (new \DateTimeImmutable("$y-01-01"))->format('Y-m-d');
    $end   = (new \DateTimeImmutable("$y-12-31"))->format('Y-m-d');

    $engins = $this->em->getRepository(Engin::class)->findBy(
      ['entite' => $entite],
      ['nom' => 'ASC']
    );

    $users = $this->em->createQueryBuilder()
      ->select('u')
      ->from(Utilisateur::class, 'u')
      ->innerJoin(UtilisateurEntite::class, 'ue', 'WITH', 'ue.utilisateur = u')
      ->andWhere('ue.entite = :entite')
      ->setParameter('entite', $entite)
      ->orderBy('u.nom', 'ASC')
      ->addOrderBy('u.prenom', 'ASC')
      ->getQuery()
      ->getResult();

    return $this->render('administrateur/carburant/dashboard.html.twig', [
      'entite' => $entite,
      'start' => $start,
      'end' => $end,
      'engins' => $engins,
      'employes' => $users,

      // ✅ NEW : enums pour générer les <option>
      'categorieProduits' => CategorieProduit::cases(),
      'sousCategorieProduits' => SousCategorieProduit::cases(),
    ]);
  }

  #[Route('/api/summary', name: 'api_summary', methods: ['GET'])]
  public function apiSummary(Entite $entite, Request $req): JsonResponse
  {
    $f = $this->filters($req);
    $data = $this->repo->summary($entite, $f);

    return new JsonResponse([
      'filters' => $f,
      'kpis' => $data['kpis'],
      'charts' => $data['charts'],
    ]);
  }

  #[Route('/dt/transactions', name: 'dt_transactions', methods: ['GET'])]
  public function dtTransactions(Entite $entite, Request $req): JsonResponse
  {
    $draw = (int)$req->query->get('draw', 0);
    $start = max(0, (int)$req->query->get('start', 0));
    $length = (int)$req->query->get('length', 25);
    if ($length <= 0 || $length > 200) $length = 25;

    $search = trim((string)($req->query->all('search')['value'] ?? ''));

    $order = $req->query->all('order');
    $colIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
    $dir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $map = [
      0 => 'date_tx',
      1 => 'provider',
      2 => 'engin_label',
      3 => 'employe_label',
      4 => 'label',
      5 => 'produit_cat',   // ✅ au lieu de categorie_produit
      6 => 'produit_sous',
      7 => 'qty',
      8 => 'amount_cents',
    ];
    $orderBy = $map[$colIdx] ?? 'date_tx';

    $f = $this->filters($req);

    [$rows, $total, $filtered] = $this->repo->fetchRows($entite, $f, $start, $length, $orderBy, $dir, $search);


    $data = array_map(function (array $r) {
      $catVal  = $r['produit_cat'] ?? null;   // ✅
      $sousVal = $r['produit_sous'] ?? null;  // ✅

      $catLabel = '-';
      if (is_string($catVal) && $catVal !== '') {
        try {
          $catLabel = CategorieProduit::from($catVal)->label();
        } catch (\Throwable) {
        }
      }

      $sousLabel = '-';
      if (is_string($sousVal) && $sousVal !== '') {
        try {
          $sousLabel = SousCategorieProduit::from($sousVal)->label();
        } catch (\Throwable) {
        }
      }

      $providerRaw = strtolower((string)($r['provider'] ?? ''));

      $providerLabel = match ($providerRaw) {
        'alx' => 'ALX',
        'total' => 'TOTAL',
        'edenred' => 'EDENRED',
        'note' => 'NOTE',
        default => '-',
      };

      return [
        'date' => $r['date_tx'] ? (new \DateTimeImmutable($r['date_tx']))->format('d/m/Y') : '-',
        'provider' => $providerLabel,
        'engin' => $r['engin_label'] ?? '-',
        'employe' => $r['employe_label'] ?? '-',
        'label' => $r['label'] ?? '-',
        'categorie' => $catLabel !== '' ? $catLabel : '-',
        'sousCategorie' => $sousLabel !== '' ? $sousLabel : '-',

        'qty' => (float)($r['qty'] ?? 0),
        'amount_cents' => (int)($r['amount_cents'] ?? 0),
      ];
    }, $rows);

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $total,
      'recordsFiltered' => $filtered,
      'data' => $data,
    ]);
  }

  private function filters(Request $req): array
  {
    $parse = function (?string $s, string $fallback): string {
      $s = trim((string) $s);
      if ($s === '') {
        return (new \DateTimeImmutable($fallback))->format('Y-m-d');
      }

      $d = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
      if ($d instanceof \DateTimeImmutable) {
        return $d->format('Y-m-d');
      }

      $d = \DateTimeImmutable::createFromFormat('d/m/Y', $s);
      if ($d instanceof \DateTimeImmutable) {
        return $d->format('Y-m-d');
      }

      try {
        return (new \DateTimeImmutable($s))->format('Y-m-d');
      } catch (\Throwable) {
        return (new \DateTimeImmutable($fallback))->format('Y-m-d');
      }
    };

    $ds = $parse($req->query->get('dateStart'), 'first day of january');
    $de = $parse($req->query->get('dateEnd'), 'last day of december');

    $providersNone        = (bool) $req->query->get('providersNone', false);
    $enginNone            = (bool) $req->query->get('enginNone', false);
    $employeNone          = (bool) $req->query->get('employeNone', false);
    $categorieNone        = (bool) $req->query->get('categorieNone', false);
    $sousCategorieNone    = (bool) $req->query->get('sousCategorieNone', false);
    $enginUncategorized   = (bool) $req->query->get('enginUncategorized', false);
    $employeUncategorized = (bool) $req->query->get('employeUncategorized', false);
    $categorieUncategorized = (bool) $req->query->get('categorieUncategorized', false);
    $sousUncategorized = (bool) $req->query->get('sousUncategorized', false);

    // ✅ Providers
    $providers = $req->query->all('providers');
    $providers = is_array($providers) ? $providers : [];
    $providers = array_values(array_intersect(['ALX', 'TOTAL', 'EDENRED', 'NOTE'], $providers));

    // ✅ fallback "Tous" seulement si PAS en mode None
    if (!$providersNone && $providers === []) {
      $providers = ['ALX', 'TOTAL', 'EDENRED', 'NOTE'];
    }

    // ✅ Engins
    $enginIds = $req->query->all('enginIds');
    $enginIds = is_array($enginIds) ? array_values(array_filter(array_map('intval', $enginIds))) : [];
    if ($enginNone) {
      $enginIds = [];
      $enginUncategorized = false;
    }

    // ✅ Employés
    $employeIds = $req->query->all('employeIds');
    $employeIds = is_array($employeIds) ? array_values(array_filter(array_map('intval', $employeIds))) : [];
    if ($employeNone) {
      $employeIds = [];
      $employeUncategorized = false;
    }

    $allowedCats = array_map(fn($c) => $c->value, CategorieProduit::cases());
    $allowedSous = array_map(fn($s) => $s->value, SousCategorieProduit::cases());

    // --- Catégories ---
    $categorieProduitsRaw = $req->query->all('categorieProduits');
    $categorieProduitsRaw = is_array($categorieProduitsRaw) ? $categorieProduitsRaw : [];
    $categorieProduits = array_values(array_intersect($allowedCats, $categorieProduitsRaw));

    if ($categorieNone) {
      $categorieProduits = [];
      $categorieUncategorized = false;
    }

    // --- Sous-catégories ---
    $sousProduitsRaw = $req->query->all('sousCategorieProduits');
    $sousProduitsRaw = is_array($sousProduitsRaw) ? $sousProduitsRaw : [];
    $sousCategorieProduits = array_values(array_intersect($allowedSous, $sousProduitsRaw));

    if ($sousCategorieNone) {
      $sousCategorieProduits = [];
      $sousUncategorized = false;
    }

    return [
      'dateStart' => $ds,
      'dateEnd' => $de . ' 23:59:59',

      'providersNone' => $providersNone,
      'providers' => $providers,

      'enginNone' => $enginNone,
      'enginIds' => $enginIds,

      'employeNone' => $employeNone,
      'employeIds' => $employeIds,

      'categorieNone' => $categorieNone,
      'categorieProduits' => $categorieProduits,
      'categorieUncategorized' => $categorieUncategorized,

      'sousCategorieNone' => $sousCategorieNone,
      'sousCategorieProduits' => $sousCategorieProduits,
      'sousUncategorized' => $sousUncategorized,

      'enginUncategorized' => $enginUncategorized,
      'employeUncategorized' => $employeUncategorized,

    ];
  }
}
