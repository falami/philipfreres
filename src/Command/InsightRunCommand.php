<?php

declare(strict_types=1);
namespace App\Command;

use App\Service\Intescia\{InsightStore, InsightImporter, InsightAlerts, InsightDigest, InsightException};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:intescia:run', description: 'Imports demandés / horaires et alertes des marchés suivis.')]
final class InsightRunCommand extends Command
{
    public function __construct(private readonly InsightStore $store, private readonly InsightImporter $importer, private readonly InsightAlerts $alerts, private readonly InsightDigest $digest) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limiter à une entité')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter les alertes, sans appel API, modification ni envoi');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = $this->store->db->fetchFirstColumn('SELECT tenant_id FROM insight_connection ORDER BY tenant_id');
        $failed = false;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($input->getOption('tenant') !== null && (string) $id !== (string) $input->getOption('tenant')) { continue; }
            try {
                $this->store->locked($id, function () use ($id, $input, $output, &$failed): void {
                    $config = $this->store->config($id);
                    $dry = (bool) $input->getOption('dry-run');
                    if (!$dry && ($config['queued'] || ($config['auto_sync'] && (!$config['last_attempt'] || strtotime($config['last_attempt'].' UTC') < time() - 3600)))) {
                        $config['queued'] = false;
                        $config['last_attempt'] = InsightStore::now();
                        $config['sync_ok'] = false;
                        $this->store->saveConfig($id, $config);
                        try {
                            $result = $this->importer->run($id);
                            $config['last_success'] = InsightStore::now();
                            $config['sync_ok'] = true;
                            $config['message'] = $result['rows'].' marchés / lots importés · '.$result['calls'].' appels API (ce nombre ne mesure pas les crédits).';
                        } catch (\Throwable $e) {
                            $config['auto_sync'] = false;
                            $config['message'] = $e instanceof InsightException ? $e->getMessage() : 'Import interrompu. Vérifiez le service et la base ; les données déjà enregistrées sont conservées.';
                            $failed = true;
                        }
                        $this->store->saveConfig($id, $config);
                        $output->writeln('Entité '.$id.' : '.$config['message']);
                    }
                    $result = $this->alerts->run($id, $dry);
                    $output->writeln(sprintf('Entité %d : %d alertes éligibles, %d envoyées, %d à vérifier%s.', $id, $result['eligible'], $result['sent'], $result['uncertain'], $dry ? ' (simulation)' : ''));
                    if ($result['uncertain']) { $failed = true; }
                    $digest = $this->digest->run($id, $dry);
                    $output->writeln(sprintf('Entité %d : %d récapitulatifs éligibles, %d envoyés, %d à vérifier%s.', $id, $digest['eligible'], $digest['sent'], $digest['uncertain'], $dry ? ' (simulation)' : ''));
                    if ($digest['uncertain']) { $failed = true; }
                });
            } catch (\Throwable $e) {
                $output->writeln('Entité '.$id.' : '.($e instanceof InsightException ? $e->getMessage() : 'Opération interrompue ; configuration à vérifier.'));
                $failed = true;
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
