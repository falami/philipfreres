<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\GeoAddressCache;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/geo', name: 'app_administrateur_geo_')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class GeoAddressCacheController extends AbstractController
{
  #[Route('/reconcile', name: 'reconcile', methods: ['GET'])]
  public function reconcile(Entite $entite, EntityManagerInterface $em, Request $request): Response
  {
    $q = trim((string) $request->query->get('q', ''));
    $onlyMissing = (bool) $request->query->get('missing', false);

    $qb = $em->getRepository(GeoAddressCache::class)->createQueryBuilder('g')
      ->andWhere('g.entite = :e')->setParameter('e', $entite)
      ->orderBy('g.id', 'DESC');

    if ($q !== '') {
      $qb->andWhere('g.address LIKE :q OR g.displayName LIKE :q OR g.addrHash LIKE :q')
        ->setParameter('q', '%' . $q . '%');
    }
    if ($onlyMissing) {
      $qb->andWhere('g.lat IS NULL OR g.lng IS NULL');
    }

    $rows = $qb->getQuery()->getResult();

    return $this->render('administrateur/geo/reconcile.html.twig', [
      'entite' => $entite,
      'rows' => $rows,
      'q' => $q,
      'onlyMissing' => $onlyMissing,
    ]);
  }

  #[Route('/cache/{id}/update', name: 'cache_update', methods: ['POST'])]
  public function update(Entite $entite, GeoAddressCache $row, Request $request, EntityManagerInterface $em): JsonResponse
  {
    if ($row->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    // CSRF (simple et efficace)
    $token = (string) $request->headers->get('X-CSRF-TOKEN', '');
    if (!$this->isCsrfTokenValid('geo_reconcile', $token)) {
      return $this->json(['ok' => false, 'error' => 'CSRF invalid'], 419);
    }

    $data = json_decode($request->getContent(), true);
    if (!is_array($data)) {
      return $this->json(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $lat = isset($data['lat']) ? (float) $data['lat'] : null;
    $lng = isset($data['lng']) ? (float) $data['lng'] : null;

    // autorise NULL
    $row->setLat($data['lat'] === null ? null : $lat);
    $row->setLng($data['lng'] === null ? null : $lng);

    if (array_key_exists('address', $data) && is_string($data['address'])) {
      $row->setAddress(trim($data['address']));
    }
    if (array_key_exists('displayName', $data)) {
      $row->setDisplayName($data['displayName'] ? trim((string) $data['displayName']) : null);
    }

    // Marque comme “corrigé manuellement”
    $row->setProvider('note');
    $row->setGeocodedAt(new \DateTimeImmutable());
    $row->setConfidence(100);

    $em->flush();

    return $this->json([
      'ok' => true,
      'id' => $row->getId(),
      'lat' => $row->getLat(),
      'lng' => $row->getLng(),
      'address' => $row->getAddress(),
      'displayName' => $row->getDisplayName(),
      'provider' => $row->getProvider(),
    ]);
  }
}
