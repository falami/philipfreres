<?php
// src/Command/GeoGeocodeCommand.php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GeoAddressCacheRepository;
use App\Service\Geo\NominatimGeocoder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'geo:geocode',
  description: 'Géocode les adresses de geo_address_cache (lat/lng NULL) pour une entité'
)]
final class GeoGeocodeCommand extends Command
{
  public function __construct(
    private readonly GeoAddressCacheRepository $geoRepo,
    private readonly NominatimGeocoder $geocoder,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->addArgument('entiteId', InputArgument::REQUIRED, 'ID Entité')
      ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nb max à traiter', 200)
      ->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED, 'Pause entre requêtes (ms)', 1100)
      ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ne met pas à jour la DB');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $entiteId = (int)$input->getArgument('entiteId');
    $limit = (int)$input->getOption('limit');
    $sleepMs = (int)$input->getOption('sleep-ms');
    $dryRun = (bool)$input->getOption('dry-run');

    $rows = $this->geoRepo->findToGeocode($entiteId, $limit);

    if (!$rows) {
      $output->writeln('<info>Aucune adresse à géocoder.</info>');
      return Command::SUCCESS;
    }

    $ok = 0;
    $fail = 0;

    foreach ($rows as $r) {
      $id = (int)$r['id'];
      $addr = (string)($r['address'] ?? '');

      $output->write("[$id] $addr ... ");

      $geo = null;
      try {
        $geo = $this->geocoder->geocode($addr);
      } catch (\Throwable $e) {
        $geo = null;
      }

      if (!$geo || $geo['lat'] === null || $geo['lng'] === null) {
        $fail++;
        $output->writeln('<error>KO</error>');
      } else {
        $ok++;
        $output->writeln('<info>OK</info>');

        if (!$dryRun) {
          $this->geoRepo->markGeocoded(
            $id,
            (float)$geo['lat'],
            (float)$geo['lng'],
            $geo['provider'] ?? 'nominatim',
            $geo['display_name'] ?? null,
            $geo['confidence'] ?? null,
          );
        }
      }

      if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
      }
    }

    $output->writeln("");
    $output->writeln("Résultat: OK=$ok / KO=$fail / total=" . count($rows));

    return Command::SUCCESS;
  }
}
