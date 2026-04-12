<?php
// src/Controller/Administrateur/EnginExternalIdAllController.php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\EnginExternalId;
use App\Enum\ExternalProvider;
use App\Repository\EnginExternalIdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/engins/external-ids', name: 'app_administrateur_engin_external_id_all_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class EnginExternalIdAllController extends AbstractController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly EnginExternalIdRepository $repo,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    // Si tu as ta permission multi-tenant, tu peux la remettre ici :
    // #[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]

    return $this->render('administrateur/engin_external_id/all.html.twig', [
      'entite' => $entite,
      'providers' => ExternalProvider::cases(),
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request): JsonResponse
  {
    $draw   = (int) $request->request->get('draw', 1);
    $start  = max(0, (int) $request->request->get('start', 0));
    $length = (int) $request->request->get('length', 25);
    $length = $length <= 0 ? 25 : min($length, 200);

    // Filtres custom envoyés par le Twig
    $providerFilter = (string) $request->request->get('providerFilter', 'all'); // 'all' ou 'total'/'alx'/...
    $activeFilter   = (string) $request->request->get('activeFilter', 'all');   // 'all' | '1' | '0'
    $searchAny = trim((string) $request->request->get('searchAny', ''));

    // ✅ DataTables global search (input en haut à droite)
    $dtSearch = trim((string) ($request->request->all('search')['value'] ?? ''));

    // ✅ si DataTables est utilisé, on l’applique aussi
    // (tu peux concaténer, ou donner la priorité au dtSearch)
    $search = $dtSearch !== '' ? $dtSearch : $searchAny;

    // DataTables order
    $order = $request->request->all('order')[0] ?? null;
    $columns = $request->request->all('columns') ?? [];

    $orderColIdx = isset($order['column']) ? (int) $order['column'] : 0;
    $orderDir    = (isset($order['dir']) && strtolower((string) $order['dir']) === 'asc') ? 'ASC' : 'DESC';

    // Mapping index -> champ DB (alias "x" pour EnginExternalId)
    $colMap = [
      0 => 'x.id',
      1 => 'e.nom',         // Engin (ajuste si tu veux immat etc)
      2 => 'x.provider',
      3 => 'x.value',
      4 => 'x.active',
      5 => 'x.createdAt',
      6 => 'x.disabledAt',
      7 => 'x.note',
    ];
    $orderBy = $colMap[$orderColIdx] ?? 'x.id';

    // Comptage total (sans filtre)
    $recordsTotal = $this->repo->countAllForEntite($entite);

    // Résultats filtrés + paginés
    $result = $this->repo->fetchAllForEntiteDataTable(
      entite: $entite,
      start: $start,
      length: $length,
      orderBy: $orderBy,
      orderDir: $orderDir,
      providerFilter: $providerFilter,
      activeFilter: $activeFilter,
      searchAny: $search,
    );

    $rows = $result['rows'];
    $recordsFiltered = $result['filtered'];

    // Format DataTables
    $data = [];
    foreach ($rows as $r) {
      /** @var EnginExternalId $x */
      $x = $r['x'];
      $engin = $r['e'];

      $enginLabel = method_exists($engin, 'getNom') ? (string) $engin->getNom() : ('Engin #' . $engin->getId());
      // Si tu as une immat, tu peux enrichir :
      // $enginLabel .= $engin->getImmatriculation() ? ' (' . $engin->getImmatriculation() . ')' : '';

      $data[] = [
        'id' => $x->getId(),
        'engin' => $enginLabel,
        'provider' => $x->getProvider()->value,
        'value' => $x->getValue(),
        'active' => $x->isActive(),
        'createdAt' => $x->getCreatedAt()->format('d/m/Y H:i'),
        'disabledAt' => $x->getDisabledAt()?->format('d/m/Y H:i'),
        'note' => $x->getNote(),
        // ✅ CSRF par ligne (important pour l’AJAX)
        'csrfDisable' => $this->container->get('security.csrf.token_manager')->getToken('disable_engin_ext_' . $x->getId())->getValue(),
        'csrfEnable'  => $this->container->get('security.csrf.token_manager')->getToken('enable_engin_ext_' . $x->getId())->getValue(),
        // actions HTML (tu peux adapter à ton style btn-group)
        'actions' => $this->renderView('administrateur/engin_external_id/_actions_all.html.twig', [
          'entite' => $entite,
          'engin' => $engin,
          'item' => $x,
        ]),
      ];
    }

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  #[Route('/{id}/disable', name: 'disable', methods: ['POST'])]
  public function disable(Entite $entite, EnginExternalId $id, Request $request): JsonResponse
  {
    // Sécurité entite : on vérifie que l’ID appartient à l’entite
    if ($id->getEngin()?->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['message' => 'Ressource introuvable.'], 404);
    }

    if (!$this->isCsrfTokenValid('disable_engin_ext_' . $id->getId(), (string) $request->request->get('_token'))) {
      return $this->json(['message' => 'Token CSRF invalide.'], 419);
    }

    $note = trim((string) $request->request->get('note', ''));
    $id->disable($note !== '' ? $note : null);
    $this->em->flush();

    return $this->json(['ok' => true]);
  }

  #[Route('/{id}/enable', name: 'enable', methods: ['POST'])]
  public function enable(Entite $entite, EnginExternalId $id, Request $request): JsonResponse
  {
    if ($id->getEngin()?->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['message' => 'Ressource introuvable.'], 404);
    }

    if (!$this->isCsrfTokenValid('enable_engin_ext_' . $id->getId(), (string) $request->request->get('_token'))) {
      return $this->json(['message' => 'Token CSRF invalide.'], 419);
    }

    // Réactivation
    $ref = new \ReflectionClass($id);
    // On évite d’ajouter une méthode "enable()" si tu veux rester minimal :
    // on remet active=true et disabledAt=null
    $propActive = $ref->getProperty('active');
    $propActive->setAccessible(true);
    $propActive->setValue($id, true);

    $propDisabled = $ref->getProperty('disabledAt');
    $propDisabled->setAccessible(true);
    $propDisabled->setValue($id, null);

    $this->em->flush();

    return $this->json(['ok' => true]);
  }
}
