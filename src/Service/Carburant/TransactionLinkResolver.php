<?php
// src/Service/Carburant/TransactionLinkResolver.php

namespace App\Service\Carburant;

use App\Entity\{Entite, TransactionCarteAlx, TransactionCarteTotal, TransactionCarteEdenred};
use App\Service\Carburant\EnginMatcher;

final class TransactionLinkResolver
{
  public function __construct(
    private readonly EnginMatcher $matcher,
  ) {}

  public function resolveAlx(Entite $entite, TransactionCarteAlx $t, bool $force = false): void
  {
    if (!$force && $t->getEngin()) {
      $this->resolveEmployeFromEngin($t->getEngin(), $t->getJournee(), $t);
      return;
    }

    $engin = $this->matcher->matchForAlx($entite, $t);
    $t->setEngin($engin);
    $this->resolveEmployeFromEngin($engin, $t->getJournee(), $t);
  }

  public function resolveTotal(Entite $entite, TransactionCarteTotal $t, bool $force = false): void
  {
    if (!$force && $t->getEngin()) {
      $this->resolveEmployeFromEngin($t->getEngin(), $t->getDateTransaction(), $t);
      return;
    }

    $engin = $this->matcher->matchForTotal($entite, $t);
    $t->setEngin($engin);
    $this->resolveEmployeFromEngin($engin, $t->getDateTransaction(), $t);
  }

  public function resolveEdenred(Entite $entite, TransactionCarteEdenred $t, bool $force = false): void
  {
    if (!$force && $t->getEngin()) {
      $this->resolveEmployeFromEngin($t->getEngin(), $t->getDateTransaction(), $t);
      return;
    }

    $engin = $this->matcher->matchForEdenred($entite, $t);
    $t->setEngin($engin);
    $this->resolveEmployeFromEngin($engin, $t->getDateTransaction(), $t);
  }

  private function resolveEmployeFromEngin($engin, ?\DateTimeImmutable $date, object $t): void
  {
    // si pas d’engin ou date -> on ne peut pas déduire
    if (!$engin || !$date) {
      if (method_exists($t, 'setEmploye')) $t->setEmploye(null);
      return;
    }
  }
}
