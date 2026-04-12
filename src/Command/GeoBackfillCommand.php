<?php
// src/Command/GeoBackfillCommand.php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GeoAddressCacheBackfillRepository;
use App\Repository\EntiteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'geo:backfill',
  description: 'Remplit geo_address_cache avec les adresses distinctes issues des transactions.'
)]
final class GeoBackfillCommand extends Command
{
  public function __construct(
    private readonly EntiteRepository $entiteRepo,
    private readonly GeoAddressCacheBackfillRepository $repo,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->addArgument('entiteId', InputArgument::OPTIONAL, 'ID entité (sinon toutes)')
      ->addOption('alx-address', null, InputOption::VALUE_OPTIONAL, 'Adresse fixe ALX à injecter', null);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $entiteId = $input->getArgument('entiteId');
    $alxAddress = $input->getOption('alx-address');

    $entites = [];
    if ($entiteId) {
      $e = $this->entiteRepo->find((int)$entiteId);
      if (!$e) {
        $output->writeln('<error>Entité introuvable.</error>');
        return Command::FAILURE;
      }
      $entites = [$e];
    } else {
      $entites = $this->entiteRepo->findAll();
    }

    $totalInserted = 0;

    foreach ($entites as $entite) {
      $inserted = $this->repo->backfillDistinctAddresses($entite->getId(), $alxAddress);
      $totalInserted += $inserted;
      $output->writeln(sprintf('Entité #%d: %d lignes insérées (ou mises à jour).', $entite->getId(), $inserted));
    }

    $output->writeln(sprintf('<info>Total: %d</info>', $totalInserted));
    return Command::SUCCESS;
  }
}
