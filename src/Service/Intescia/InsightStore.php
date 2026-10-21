<?php

declare(strict_types=1);
namespace App\Service\Intescia;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class InsightStore
{
    public function __construct(public readonly Connection $db, #[Autowire('%kernel.project_dir%/var/intescia-locks')] private readonly string $lockDirectory) {}

    public function locked(int $tenant, callable $work): mixed
    {
        if (!is_dir($this->lockDirectory) && !mkdir($this->lockDirectory, 0700, true) && !is_dir($this->lockDirectory)) {
            throw new InsightException('Répertoire de verrouillage indisponible.');
        }
        $lock = (new LockFactory(new FlockStore($this->lockDirectory)))->createLock('tenant-'.$tenant);
        if (!$lock->acquire()) { throw new InsightException('Une opération Intescia est déjà en cours. Réessayez après sa fin.'); }
        try { return $work(); } finally { $lock->release(); }
    }

    public function config(int $tenant): array
    {
        $json = $this->db->fetchOne('SELECT settings FROM insight_connection WHERE tenant_id = ?', [$tenant]);
        return array_replace([
            'statuses' => [], 'status_catalog' => [], 'recipients' => [], 'lead_hours' => 48,
            'digest_enabled' => false, 'digest_interval' => 1, 'digest_time' => '08:00', 'digest_start' => null,
            'alerts' => false, 'auto_sync' => false, 'include_details' => true, 'include_lots' => true,
            'max_calls' => 100, 'custom_fields' => [], 'queued' => false, 'last_success' => null,
            'last_attempt' => null, 'message' => 'Connexion à configurer.', 'account' => null, 'authorized' => false,
        ], $json ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : []);
    }

    public function saveConfig(int $tenant, array $settings): void
    {
        $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($this->db->fetchOne('SELECT tenant_id FROM insight_connection WHERE tenant_id = ?', [$tenant]) !== false) {
            $this->db->update('insight_connection', ['settings' => $json], ['tenant_id' => $tenant]);
        } else {
            $this->db->insert('insight_connection', ['tenant_id' => $tenant, 'settings' => $json]);
        }
    }

    public function credentials(int $tenant): ?string
    {
        return $this->db->fetchOne('SELECT credentials FROM insight_connection WHERE tenant_id = ?', [$tenant]) ?: null;
    }

    public function saveCredentials(int $tenant, ?string $encrypted): void
    {
        $this->saveConfig($tenant, $this->config($tenant));
        $this->db->update('insight_connection', ['credentials' => $encrypted], ['tenant_id' => $tenant]);
    }

    public static function id(int $tenant, string $remote): string { return hash('sha256', $tenant.':'.$remote); }
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }

    public function market(int $tenant, string $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM insight_market WHERE tenant_id = ? AND id = ?', [$tenant, $id]);
        if (!$row) { return null; }
        $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function cache(int $tenant, string $contract): array
    {
        $json = $this->db->fetchOne('SELECT payload FROM insight_contract_cache WHERE tenant_id = ? AND id = ?', [$tenant, self::id($tenant, $contract)]);
        return $json ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function saveCache(int $tenant, string $contract, array $payload): void
    {
        $id = self::id($tenant, $contract);
        $data = ['tenant_id' => $tenant, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)];
        if ($this->db->fetchOne('SELECT id FROM insight_contract_cache WHERE id = ?', [$id])) {
            $this->db->update('insight_contract_cache', $data, ['id' => $id]);
        } else { $this->db->insert('insight_contract_cache', ['id' => $id] + $data); }
    }

    public function saveMarket(int $tenant, array $row, array $payload, string $generation): void
    {
        $id = self::id($tenant, $row['remote_id']);
        $data = $row + ['tenant_id' => $tenant, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'seen' => $generation, 'active' => 1, 'updated_at' => self::now()];
        if ($this->db->fetchOne('SELECT id FROM insight_market WHERE id = ?', [$id])) {
            $this->db->update('insight_market', $data, ['id' => $id, 'tenant_id' => $tenant]);
        } else { $this->db->insert('insight_market', ['id' => $id] + $data); }
    }
}
