<?php
// src/Controller/Administrateur/UtilisateurExternalIdAllCardsController.php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Enum\ExternalProvider;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/utilisateurs/external-ids/cards', name: 'app_administrateur_utilisateur_external_id_cards_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')] // adapte si tu as une permission USER_MANAGE
final class UtilisateurExternalIdAllCardsController extends AbstractController
{
  public function __construct(private readonly EntityManagerInterface $em) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/utilisateur_external_id/cards.html.twig', [
      'entite' => $entite,
      'providers' => ExternalProvider::cases(),
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['GET'])]
  public function ajax(Entite $entite, Request $request): JsonResponse
  {
    $q = trim((string) $request->query->get('q', ''));
    $onlyActive = (int) $request->query->get('onlyActive', 1) === 1;

    // providers[]=total&providers[]=edenred...
    $providers = (array) $request->query->all('providers');
    $providers = array_values(array_filter(array_map('strval', $providers)));

    // Si rien coché => on considère "tous"
    $filterProviders = [];
    foreach ($providers as $p) {
      try {
        $filterProviders[] = ExternalProvider::from($p);
      } catch (\Throwable) {
      }
    }

    // Query : utilisateurs + externalIds
    // Hypothèse : tu relies les utilisateurs à une entité via Utilisateur::entite (tu l’as dans l’entité)
    $qb = $this->em->createQueryBuilder()
      ->select('u', 'x')
      ->from(\App\Entity\Utilisateur::class, 'u')
      ->leftJoin('u.externalIds', 'x')
      ->andWhere('u.entite = :entite')
      ->setParameter('entite', $entite);

    if ($onlyActive) {
      $qb->andWhere('(x.id IS NULL OR x.active = true)'); // affiche users même sans externalId
    }

    if (!empty($filterProviders)) {
      $qb->andWhere('(x.id IS NULL OR x.provider IN (:prov))')
        ->setParameter('prov', $filterProviders);
    }

    if ($q !== '') {
      // on cherche sur nom/prenom/email + valeurs externalId
      $qb->andWhere('(
                LOWER(u.nom) LIKE :q
                OR LOWER(u.prenom) LIKE :q
                OR LOWER(u.email) LIKE :q
                OR LOWER(COALESCE(x.value, \'\')) LIKE :q
                OR LOWER(COALESCE(x.note, \'\')) LIKE :q
            )')
        ->setParameter('q', '%' . mb_strtolower($q) . '%');
    }

    $qb->orderBy('u.prenom', 'ASC')
      ->addOrderBy('u.nom', 'ASC');

    $users = $qb->getQuery()->getResult();

    /**
     * On restructure :
     * [
     *   userId => [
     *     user => Utilisateur,
     *     map => [ 'total' => [values...], 'edenred'=>[], 'alx'=>[] ],
     *     counts => ...
     *   ]
     * ]
     */
    $byUser = [];
    foreach ($users as $u) {
      $uid = $u->getId();
      if (!isset($byUser[$uid])) {
        $byUser[$uid] = [
          'user' => $u,
          'map' => [
            ExternalProvider::TOTAL->value => [],
            ExternalProvider::EDENRED->value => [],
            ExternalProvider::ALX->value => [],
          ],
          'hasAny' => false,
        ];
      }

      // ⚠️ comme on fait leftJoin u.externalIds, Doctrine te ramène les externalIds via $u->getExternalIds()
      // donc on ne remplit pas ici depuis "x", on remplira via la collection.
    }

    // Remplissage réel des badges depuis la collection (plus fiable)
    foreach ($byUser as $uid => $row) {
      $u = $row['user'];
      foreach ($u->getExternalIds() as $x) {
        if ($onlyActive && !$x->isActive()) continue;
        if (!empty($filterProviders) && !in_array($x->getProvider(), $filterProviders, true)) continue;

        $p = $x->getProvider()->value;
        $val = $x->getValue();
        if ($val === '') continue;

        $byUser[$uid]['map'][$p][] = $val;
        $byUser[$uid]['hasAny'] = true;
      }

      // dédoublonnage + limite visuelle
      foreach ($byUser[$uid]['map'] as $p => $vals) {
        $vals = array_values(array_unique($vals));
        sort($vals);
        $byUser[$uid]['map'][$p] = $vals;
      }
    }

    // Si on a filtré par providers, tu peux vouloir n’afficher que les users qui ont au moins une correspondance
    // (sinon tu verras plein de cards "vides")
    if (!empty($filterProviders) || $q !== '') {
      $byUser = array_filter($byUser, fn($r) => $r['hasAny'] || $q !== '');
    }

    $html = $this->renderView('administrateur/utilisateur_external_id/_cards_grid.html.twig', [
      'entite' => $entite,
      'items' => array_values($byUser),
    ]);

    return $this->json([
      'ok' => true,
      'html' => $html,
      'count' => count($byUser),
    ]);
  }
}
