<?php
// src/Controller/Administrateur/UtilisateurExternalIdAllController.php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\UtilisateurExternalId;
use App\Enum\ExternalProvider;
use App\Repository\UtilisateurExternalIdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/utilisateurs/external-ids', name: 'app_administrateur_utilisateur_external_id_all_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')] // adapte si tu as une permission USER_MANAGE
final class UtilisateurExternalIdAllController extends AbstractController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly UtilisateurExternalIdRepository $repo,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/utilisateur_external_id/all.html.twig', [
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
    $providerFilter = (string) $request->request->get('providerFilter', 'all');
    $activeFilter   = (string) $request->request->get('activeFilter', 'all');
    $searchAny      = trim((string) $request->request->get('searchAny', ''));

    // ✅ DataTables global search (champ natif DataTables)
    $dtSearch = trim((string) (($request->request->all('search')['value'] ?? '') ?: ''));

    // ✅ On unifie : priorité au champ DataTables si utilisé
    $search = $dtSearch !== '' ? $dtSearch : $searchAny;

    // DataTables order
    $order = $request->request->all('order')[0] ?? null;
    $orderColIdx = isset($order['column']) ? (int) $order['column'] : 0;
    $orderDir    = (isset($order['dir']) && strtolower((string) $order['dir']) === 'asc') ? 'ASC' : 'DESC';

    // Mapping index -> champ DB (alias x = UtilisateurExternalId)
    // alias u = Utilisateur
    $colMap = [
      0 => 'x.id',
      1 => 'u.nom',       // adapte si tu as lastname/firstname
      2 => 'u.prenom',
      3 => 'x.provider',
      4 => 'x.value',
      5 => 'x.active',
      6 => 'x.createdAt',
      7 => 'x.disabledAt',
      8 => 'x.note',
    ];
    $orderBy = $colMap[$orderColIdx] ?? 'x.id';

    $recordsTotal = $this->repo->countAllForEntite($entite);

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

    $tm = $this->container->get('security.csrf.token_manager');

    $data = [];
    foreach ($rows as $r) {
      /** @var UtilisateurExternalId $x */
      $x = $r['x'];
      $u = $r['u'];

      // Libellé utilisateur (adapte selon tes getters)
      $nom = method_exists($u, 'getNom') ? (string) $u->getNom() : '';
      $prenom = method_exists($u, 'getPrenom') ? (string) $u->getPrenom() : '';
      $email = method_exists($u, 'getEmail') ? (string) $u->getEmail() : '';

      $userLabel = trim(($prenom . ' ' . $nom)) ?: ('Utilisateur #' . $u->getId());
      if ($email !== '') $userLabel .= ' — ' . $email;

      $data[] = [
        'id' => $x->getId(),
        'utilisateur' => $userLabel,
        'provider' => $x->getProvider()->value,
        'value' => $x->getValue(),
        'active' => $x->isActive(),
        'createdAt' => $x->getCreatedAt()->format('d/m/Y H:i'),
        'disabledAt' => $x->getDisabledAt()?->format('d/m/Y H:i'),
        'note' => $x->getNote(),

        // ✅ CSRF par ligne
        'csrfDisable' => $tm->getToken('disable_user_ext_' . $x->getId())->getValue(),
        'csrfEnable'  => $tm->getToken('enable_user_ext_' . $x->getId())->getValue(),

        // actions HTML
        'actions' => $this->renderView('administrateur/utilisateur_external_id/_actions_all.html.twig', [
          'entite' => $entite,
          'utilisateur' => $u,
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
  public function disable(Entite $entite, UtilisateurExternalId $id, Request $request): JsonResponse
  {
    // ✅ vérifie que l'externalId appartient bien à un user de l'entite
    if ($id->getUtilisateur()?->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['message' => 'Ressource introuvable.'], 404);
    }

    if (!$this->isCsrfTokenValid('disable_user_ext_' . $id->getId(), (string) $request->request->get('_token'))) {
      return $this->json(['message' => 'Token CSRF invalide.'], 419);
    }

    $note = trim((string) $request->request->get('note', ''));
    $id->disable($note !== '' ? $note : null);
    $this->em->flush();

    return $this->json(['ok' => true]);
  }

  #[Route('/{id}/enable', name: 'enable', methods: ['POST'])]
  public function enable(Entite $entite, UtilisateurExternalId $id, Request $request): JsonResponse
  {
    if ($id->getUtilisateur()?->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['message' => 'Ressource introuvable.'], 404);
    }

    if (!$this->isCsrfTokenValid('enable_user_ext_' . $id->getId(), (string) $request->request->get('_token'))) {
      return $this->json(['message' => 'Token CSRF invalide.'], 419);
    }

    // ✅ mieux : ajoute enable() à l'entité
    $ref = new \ReflectionClass($id);

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
