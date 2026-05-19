<?php
// src/Service/Carburant/EnginMatcher.php

namespace App\Service\Carburant;

use App\Entity\{Engin, Entite, TransactionCarteAlx, TransactionCarteEdenred, TransactionCarteTotal};
use App\Enum\ExternalProvider;
use App\Repository\EnginExternalIdRepository;
use Doctrine\ORM\EntityManagerInterface;

final class EnginMatcher
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly EnginExternalIdRepository $extRepo,
  ) {}

  public function matchForTotal(Entite $entite, TransactionCarteTotal $t): ?Engin
  {
    $key = FuelKey::norm($t->getNomPersonnaliseCarte());
    if ($key) {
      $engin = $this->extRepo->findActiveEnginByProviderValue($entite, ExternalProvider::TOTAL, $key);
      if ($engin) return $engin;

      // legacy (si champ existe)
      $engin = $this->findByLegacyName($entite, 'nomTotal', $key);
      if ($engin) return $engin;
    }

    $plate = FuelKey::normPlate($t->getImmatriculationVehicule());
    if ($plate) return $this->findByPlate($entite, $plate);

    return null;
  }

  public function matchForAlx(Entite $entite, TransactionCarteAlx $t): ?Engin
  {
    $key = FuelKey::norm($t->getVehicule());
    if ($key) {
      $engin = $this->extRepo->findActiveEnginByProviderValue($entite, ExternalProvider::ALX, $key);
      if ($engin) return $engin;

      // legacy (si champ existe)
      $engin = $this->findByLegacyName($entite, 'nomAlx', $key);
      if ($engin) return $engin;
    }

    return null;
  }

  public function matchForEdenred(Entite $entite, TransactionCarteEdenred $t): ?Engin
  {
    // 1) Chez EDENRED, dans tes fichiers, le code engin est dans "kilometrage"
    $codeEngin = FuelKey::norm($t->getKilometrage());
    if ($codeEngin) {
      $engin = $this->extRepo->findActiveEnginByProviderValue($entite, ExternalProvider::EDENRED, $codeEngin);
      if ($engin) return $engin;

      $engin = $this->findByLegacyName($entite, 'nomEdenred', $codeEngin);
      if ($engin) return $engin;
    }

    // 2) Fallback plaque si présente
    $plate = FuelKey::normPlate($t->getImmatriculation());
    if ($plate) {
      $engin = $this->findByPlate($entite, $plate);
      if ($engin) return $engin;
    }

    // 3) Fallback code_chauffeur, mais seulement en dernier
    $codeChauffeur = FuelKey::norm($t->getCodeChauffeur());
    if ($codeChauffeur) {
      $engin = $this->extRepo->findActiveEnginByProviderValue($entite, ExternalProvider::EDENRED, $codeChauffeur);
      if ($engin) return $engin;
    }

    return null;
  }

  // ------------------------------------------------------------
  // ✅ Legacy fallback SAFE (anti champ inexistant)
  // ------------------------------------------------------------

  private function findByLegacyName(Entite $entite, string $field, string $normalizedLower): ?Engin
  {
    // ✅ whitelist : ajoute UNIQUEMENT ce que tu as réellement (ou veux tolérer)
    $allowed = [
      'nomTotal',
      'nomAlx',
      'nomEdenred',
    ];

    if (!in_array($field, $allowed, true)) {
      return null;
    }

    // ✅ empêche le DQL "no field named xxx"
    if (!$this->enginHasFieldOrAssociation($field)) {
      return null;
    }

    return $this->em->getRepository(Engin::class)->createQueryBuilder('e')
      ->andWhere('e.entite = :entite')->setParameter('entite', $entite)
      ->andWhere(sprintf('LOWER(e.%s) = :k', $field))->setParameter('k', $normalizedLower)
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  private function enginHasFieldOrAssociation(string $field): bool
  {
    $meta = $this->em->getClassMetadata(Engin::class);
    return $meta->hasField($field) || $meta->hasAssociation($field);
  }

  private function findByPlate(Entite $entite, string $plate): ?Engin
  {
    return $this->em->getRepository(Engin::class)->createQueryBuilder('e')
      ->andWhere('e.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('e.immatriculation = :p')->setParameter('p', $plate)
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }
}
