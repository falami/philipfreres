<?php

declare(strict_types=1);
namespace App\Service\Intescia;

final class InsightImporter
{
    private const FIELDS = ['AWARDED_AMOUNT', 'BENEFICIARIES', 'CONTRACT_TYPE', 'CUSTOMER_FIELDS', 'ESTIMATED_AMOUNT', 'ESTIMATED_AMOUNT_EXCLUDING_TAX', 'EXECUTION_CITIES', 'INTERLOCUTORS', 'NOTICES', 'SPAIN_PRESCRIPTION'];

    public function __construct(private readonly InsightStore $store, private readonly InsightClient $client) {}

    /** Caller owns the tenant lock. Failed imports never deactivate old records or authorize alerts. */
    public function run(int $tenant): array
    {
        $config = $this->store->config($tenant);
        if (!$config['statuses']) { throw new InsightException('Sélectionnez au moins un statut DoubleTrade dans les paramètres.'); }
        $this->client->budget((int) $config['max_calls']);
        $generation = bin2hex(random_bytes(16));
        $seen = [];
        $page = 0;
        do {
            $data = $this->client->request($tenant, 'POST', '/me/deal-opportunities?page='.$page.'&size=100', [
                'archived' => false,
                'additionalFields' => self::FIELDS,
                'requestedInterlocutorRoles' => ['UNKNOWN', 'ARCHITECT', 'PROJECT_MANAGER', 'PROJECT_OWNER', 'ECONOMIST', 'DESIGN_OFFICE', 'PROGRAMMER', 'SURVEYOR', 'TECHNICAL_CONTROLLER', 'REPORTER', 'LANDSCAPE_ARCHITECT', 'DELEGATE_PROJECT_OWNER', 'PROMOTER', 'GENERAL_CONTRACTOR', 'BUYER', 'SOIL_SURVEY_ENGINEER', 'SPS_ENGINEER', 'REPRESENTATIVE', 'TOWN_PLANNER', 'DESIGN_OFFICE_FLUID', 'DESIGN_OFFICE_STRUCTURES', 'DESIGN_OFFICE_ACOUSTIC', 'DESIGN_OFFICE_ROAD', 'DESIGN_OFFICE_GENERAL', 'DESIGN_OFFICE_THERMAL', 'DESIGN_OFFICE_ENVIRONMENT', 'DESIGN_OFFICE_GEOTECH', 'DESIGN_OFFICE_ELECTRICITY', 'DESIGN_OFFICE_KITCHEN', 'OPC_ENGINEER', 'SET_DESIGNER', 'OTHER', 'DESIGN_OFFICE_LIGHTING', 'SECONDARY_PRINCIPAL'],
                'collaborativeStatusesFilter' => ['collaborativeStatusIds' => array_values($config['statuses']), 'emptyCollaborativeStatus' => false],
                'customFieldConfigurationIds' => array_values($config['custom_fields']),
            ]);
            if (!isset($data['content'], $data['totalPages'], $data['number']) || !is_array($data['content']) || (int) $data['number'] !== $page) {
                throw new InsightException('Pagination Intescia inattendue. Les données existantes sont conservées.');
            }
            if ($page > 1000 || (int) $data['totalPages'] > 1001) { throw new InsightException('Sélection trop volumineuse ; réduisez les statuts suivis.'); }
            $batch = [];
            foreach ($data['content'] as $hit) {
                if (!empty($hit['metadata']['archived'])) { continue; }
                $op = $hit['dealOpportunity'] ?? [];
                $type = $op['dealOpportunityResultType'] ?? '';
                if (!in_array($type, ['CONTRACT', 'LOT', 'CONFIDENTIAL_CONTRACT', 'CONFIDENTIAL_LOT', 'RENEWAL_CONTRACT', 'RENEWAL_LOT'], true)) { continue; }
                $id = $op['id'] ?? '';
                if (isset($seen[$id])) { continue; }
                $seen[$id] = true;
                $contractId = str_contains($type, 'LOT') ? ($op['contractId'] ?? '') : $id;
                if (!preg_match('/^[a-f0-9-]{36}$/D', $contractId)) { throw new InsightException('Marché parent introuvable pour un lot.'); }
                $cache = $this->store->cache($tenant, $contractId);
                // Save every returned row before any further billable enrichment request.
                $this->store->saveMarket($tenant, InsightNormalizer::row($hit, $cache), ['followed' => $hit, 'contract_id' => $contractId], $generation);
                $batch[] = [$hit, $contractId];
            }
            foreach ($batch as [$hit, $contractId]) {
                $cache = $this->store->cache($tenant, $contractId);
                // Additional purchases happen once, or on an explicit refresh request.
                if (($config['include_details'] && !isset($cache['detail'])) || !empty($cache['refresh_detail'])) {
                    $detail = $this->client->request($tenant, 'GET', '/contracts/'.$contractId);
                    if (!isset($detail['contract']['id'])) { throw new InsightException('Fiche Intescia incomplète.'); }
                    if (isset($cache['detail'])) { $cache['previous_details'][] = ['data' => $cache['detail'], 'imported_at' => $cache['detail_imported_at'] ?? null]; }
                    $cache['detail'] = $detail;
                    unset($cache['refresh_detail']);
                    $cache['detail_imported_at'] = InsightStore::now();
                    $this->store->saveCache($tenant, $contractId, $cache);
                }
                if (($config['include_lots'] && empty($cache['lots_complete'])) || !empty($cache['refresh_lots'])) {
                    $lotPage = !empty($cache['refresh_lots']) ? 0 : (int) ($cache['next_lot_page'] ?? 0);
                    do {
                        $lots = $this->client->request($tenant, 'GET', '/contracts/'.$contractId.'/lots?page='.$lotPage.'&size=100');
                        if (!isset($lots['content'], $lots['totalPages'], $lots['number']) || !is_array($lots['content']) || (int) $lots['number'] !== $lotPage || (int) $lots['totalPages'] > 1001) {
                            throw new InsightException('Pagination des lots inattendue.');
                        }
                        if (!empty($cache['refresh_lots'])) {
                            $cache['previous_lots'][] = $cache['lot_pages'] ?? [];
                            $cache['lots'] = []; $cache['lot_pages'] = [];
                            unset($cache['refresh_lots']);
                        }
                        // Preserve complete page metadata as well as the convenient flat list.
                        $cache['lot_pages'][(string) $lotPage] = $lots;
                        $cache['lots'] = array_merge($cache['lots'] ?? [], $lots['content']);
                        $cache['next_lot_page'] = ++$lotPage;
                        $cache['lots_complete'] = $lotPage >= (int) $lots['totalPages'];
                        $this->store->saveCache($tenant, $contractId, $cache);
                    } while (!$cache['lots_complete']);
                }
                $this->store->saveMarket($tenant, InsightNormalizer::row($hit, $cache), ['followed' => $hit, 'contract_id' => $contractId], $generation);
            }
            ++$page;
        } while ($page < (int) $data['totalPages']);
        $this->store->db->executeStatement('UPDATE insight_market SET active = 0 WHERE tenant_id = ? AND seen <> ?', [$tenant, $generation]);
        return ['rows' => count($seen), 'calls' => $this->client->calls()];
    }
}
