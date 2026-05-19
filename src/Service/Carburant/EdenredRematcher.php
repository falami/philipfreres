<?php

// src/Service/Carburant/EdenredRematcher.php

namespace App\Service\Carburant;

use App\Entity\Entite;
use App\Entity\TransactionCarteEdenred;
use Doctrine\ORM\EntityManagerInterface;

final class EdenredRematcher
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly TransactionLinkResolver $resolver,
  ) {}

  public function rematchForEntite(Entite $entite): int
  {
    $count = 0;

    $rows = $this->em->getRepository(TransactionCarteEdenred::class)
      ->createQueryBuilder('t')
      ->andWhere('t.entite = :entite')
      ->setParameter('entite', $entite)
      ->getQuery()
      ->toIterable();

    foreach ($rows as $t) {
      $this->resolver->resolveEdenred($entite, $t, true);
      $count++;

      if ($count % 200 === 0) {
        $this->em->flush();
      }
    }

    $this->em->flush();

    return $count;
  }
}
