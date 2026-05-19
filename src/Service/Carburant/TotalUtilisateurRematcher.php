<?php

// src/Service/Carburant/TotalUtilisateurRematcher.php

namespace App\Service\Carburant;

use App\Entity\Entite;
use App\Entity\TransactionCarteTotal;
use App\Entity\UtilisateurExternalId;
use App\Enum\ExternalProvider;
use Doctrine\ORM\EntityManagerInterface;

final class TotalUtilisateurRematcher
{
  public function __construct(private readonly EntityManagerInterface $em) {}

  public function rematchForEntite(Entite $entite): int
  {
    $count = 0;

    $rows = $this->em->getRepository(TransactionCarteTotal::class)
      ->createQueryBuilder('t')
      ->andWhere('t.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()
      ->toIterable();

    foreach ($rows as $t) {
      $code = FuelKey::norm($t->getCodeConducteur());

      if (!$code) {
        continue;
      }

      $user = null;

      if ($code) {
        $ext = $this->em->getRepository(UtilisateurExternalId::class)
          ->createQueryBuilder('x')
          ->innerJoin('x.utilisateur', 'u')
          ->innerJoin('u.utilisateurEntites', 'ue')
          ->andWhere('ue.entite = :entite')
          ->andWhere('x.provider = :provider')
          ->andWhere('x.value = :value')
          ->andWhere('x.active = true')
          ->setParameter('entite', $entite)
          ->setParameter('provider', ExternalProvider::TOTAL)
          ->setParameter('value', $code)
          ->setMaxResults(1)
          ->getQuery()
          ->getOneOrNullResult();

        $user = $ext?->getUtilisateur();
      }

      $t->setUtilisateur($user);
      $count++;

      if ($count % 200 === 0) {
        $this->em->flush();
      }
    }

    $this->em->flush();

    return $count;
  }
}
