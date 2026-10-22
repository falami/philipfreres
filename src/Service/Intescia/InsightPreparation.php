<?php

declare(strict_types=1);
namespace App\Service\Intescia;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class InsightPreparation
{
    public const DECISIONS = ['study' => 'À étudier', 'go' => 'Répondre', 'partial' => 'Répondre à certains lots', 'no_go' => 'Ne pas répondre'];
    public const TASKS = [
        'documents' => 'Récupérer les pièces et vérifier leur version',
        'questions' => 'Lire les questions-réponses et rectificatifs',
        'requirements' => 'Confirmer visite, qualifications et pièces exigées',
        'scope' => 'Valider lots, tranches, volumes et calendrier',
        'resources' => 'Vérifier les sites et les moyens disponibles',
        'pricing' => 'Chiffrer et faire valider la marge',
        'technical' => 'Préparer le mémoire et ses preuves',
        'submission' => 'Relire et préparer le dépôt avant l’échéance',
    ];
    public function __construct(private readonly InsightStore $store) {}

    public static function defaults(): array
    {
        return ['decision' => 'study', 'owner' => '', 'target_date' => '', 'scope' => '', 'notes' => '', 'tasks' => [], 'costs' => [], 'documents' => [], 'history' => [], 'snapshot' => []];
    }

    public function load(int $tenant, string $market): array
    {
        try { $row = $this->store->db->fetchAssociative('SELECT data, revision, updated_at FROM insight_preparation WHERE tenant_id = ? AND market_id = ?', [$tenant, $market]); }
        catch (TableNotFoundException) { return ['available' => false, 'revision' => 0, 'updated_at' => null, 'data' => self::defaults()]; }
        return ['available' => true, 'revision' => $row ? (int) $row['revision'] : 0, 'updated_at' => $row['updated_at'] ?? null, 'data' => array_replace(self::defaults(), $row ? json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR) : [])];
    }

    public static function snapshot(array $payload, array $market): array
    {
        $c = $payload['enrichment']['detail']['contract'] ?? [];
        $encode = static fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $pick = static fn ($row, $keys) => array_intersect_key($row, array_flip($keys));
        $translations = static fn ($rows) => array_map(static fn ($row) => $pick($row, ['language', 'object', 'description', 'scope']), $rows);
        return [
            'Date limite (UTC)' => (string) ($market['deadline'] ?? ''),
            'Objet et description' => $encode($translations($c['translations'] ?? [])),
            'Critères de notation' => $encode(array_map(static fn ($row) => $pick($row, ['text', 'type', 'order', 'weight']), $c['awardCriteria'] ?? [])),
            'Calendrier' => $encode([$c['timeFrame']['start'] ?? null, $c['timeFrame']['duration'] ?? null, $c['timeFrame']['durationType'] ?? null, $c['initialDuration'] ?? [], $c['extensions'] ?? []]),
            'Lots' => $encode(array_map(static fn ($row) => ['id' => $row['id'] ?? null, 'number' => $row['number'] ?? null, 'translations' => $translations($row['translations'] ?? []), 'response' => $row['response'] ?? null, 'timeFrame' => $pick($row['timeFrame'] ?? [], ['start', 'end', 'duration', 'durationType'])], $payload['enrichment']['lots'] ?? [])),
        ];
    }

    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public static function changes(array $saved, array $current): array
    {
        if (!$saved) return [];
        return array_keys(array_filter($current, static fn ($value, $key) => ($saved[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_scalar($value) && $value !== null) throw new InsightException('Valeur de formulaire invalide.');
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) throw new InsightException('Un champ dépasse la longueur autorisée ('.$max.' caractères).');
        return $value;
    }
    private static function date(mixed $value): string
    {
        $value = self::text($value, 10);
        if ($value === '') return '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InsightException('Date invalide.');
        return $value;
    }
    private static function owner(mixed $value, array $users): string
    {
        $value = self::text($value, 20);
        if ($value !== '' && (!preg_match('/^[1-9][0-9]*$/D', $value) || !isset($users[(int) $value]))) throw new InsightException('Le responsable sélectionné ne fait plus partie des membres actifs.');
        return $value;
    }
    private static function decimal(mixed $value, int $places, int $max): string
    {
        $value = str_replace(',', '.', self::text($value, 30));
        if (!preg_match('/^\d+(?:\.\d{1,'.$places.'})?$/D', $value) || (float) $value > $max) throw new InsightException('Quantités et montants : nombres positifs avec '.$places.' décimales maximum.');
        return $value;
    }
    public static function validate(array $input, array $users): array
    {
        $decision = self::text($input['decision'] ?? '', 20);
        if (!isset(self::DECISIONS[$decision])) throw new InsightException('Décision invalide.');
        $data = ['decision' => $decision, 'owner' => self::owner($input['owner'] ?? '', $users), 'target_date' => self::date($input['target_date'] ?? ''), 'scope' => self::text($input['scope'] ?? '', 2000), 'notes' => self::text($input['notes'] ?? '', 20000), 'tasks' => [], 'costs' => [], 'documents' => []];
        foreach (['tasks', 'costs', 'documents'] as $key) if (isset($input[$key]) && !is_array($input[$key])) throw new InsightException('Liste de formulaire invalide.');
        foreach (self::TASKS as $id => $label) {
            $task = $input['tasks'][$id] ?? [];
            if (!is_array($task)) throw new InsightException('Tâche invalide.');
            $data['tasks'][$id] = ['done' => ($task['done'] ?? '') === '1', 'owner' => self::owner($task['owner'] ?? '', $users), 'due' => self::date($task['due'] ?? ''), 'note' => self::text($task['note'] ?? '', 1000)];
        }
        if (count($input['costs'] ?? []) > 40 || count($input['documents'] ?? []) > 30) throw new InsightException('Maximum : 40 postes de chiffrage et 30 références documentaires.');
        foreach ($input['costs'] ?? [] as $row) {
            if (!is_array($row)) throw new InsightException('Poste de chiffrage invalide.');
            $label = self::text($row['label'] ?? '', 200);
            if ($label === '') continue;
            $data['costs'][] = ['label' => $label, 'group' => self::text($row['group'] ?? '', 100), 'unit' => self::text($row['unit'] ?? '', 20), 'quantity' => self::decimal($row['quantity'] ?? '', 3, 1000000), 'cost' => self::decimal($row['cost'] ?? '', 2, 10000000), 'price' => self::decimal($row['price'] ?? '', 2, 10000000)];
        }
        foreach ($input['documents'] ?? [] as $row) {
            if (!is_array($row)) throw new InsightException('Référence documentaire invalide.');
            $title = self::text($row['title'] ?? '', 200);
            if ($title === '') continue;
            $url = self::text($row['url'] ?? '', 2000);
            if ($url !== '' && !InsightPresentation::safeUrl($url)) throw new InsightException('Les liens documentaires doivent être des URL HTTP ou HTTPS valides.');
            $status = self::text($row['status'] ?? '', 20);
            if (!in_array($status, ['missing', 'received', 'reviewed'], true)) throw new InsightException('État documentaire invalide.');
            $data['documents'][] = ['title' => $title, 'url' => $url, 'status' => $status, 'note' => self::text($row['note'] ?? '', 2000)];
        }
        self::totals($data['costs']);
        return $data;
    }

    private static function scaled(string $value, int $places): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int) $whole * (10 ** $places) + (int) str_pad($fraction, $places, '0');
    }

    public static function totals(array $rows): array
    {
        $cost = $price = 0;
        foreach ($rows as $row) {
            // Exact integer rounding of quantity (thousandths) × unit amount (cents).
            $quantity = self::scaled($row['quantity'], 3);
            $cost += intdiv($quantity * self::scaled($row['cost'], 2) + 500, 1000);
            $price += intdiv($quantity * self::scaled($row['price'], 2) + 500, 1000);
            if ($cost > 100000000000000 || $price > 100000000000000) throw new InsightException('Le total saisi dépasse la limite de chiffrage.');
        }
        return ['cost' => $cost / 100, 'price' => $price / 100, 'margin' => ($price - $cost) / 100, 'rate' => $price > 0 ? ($price - $cost) / $price * 100 : null];
    }

    public function save(int $tenant, string $market, int $revision, array $data, string $author, array $snapshot): void
    {
        if (!$this->store->market($tenant, $market)) throw new InsightException('Dossier introuvable.');
        $current = $this->load($tenant, $market);
        if (!$current['available']) throw new InsightException('Le stockage de la préparation doit être activé par la mise à jour de la base.');
        if ($current['revision'] !== $revision) throw new InsightException('Ce dossier a été modifié par un autre utilisateur. Copiez vos notes, puis rechargez la page avant de réessayer.');
        $data['history'] = array_slice(array_merge([['date' => InsightStore::now(), 'author' => $author, 'decision' => $data['decision'], 'revision' => $revision + 1, 'changed' => array_values(array_filter(['decision', 'scope', 'notes', 'tasks', 'costs', 'documents', 'owner', 'target_date'], static fn ($key) => $current['data'][$key] !== $data[$key]))]], $current['data']['history']), 0, 30);
        $data['snapshot'] = $snapshot;
        $values = ['data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'revision' => $revision + 1, 'updated_at' => InsightStore::now()];
        if ($revision === 0) {
            try { $this->store->db->insert('insight_preparation', ['tenant_id' => $tenant, 'market_id' => $market] + $values); }
            catch (UniqueConstraintViolationException) { throw new InsightException('Un autre utilisateur vient d’enregistrer ce dossier. Rechargez la page pour fusionner vos modifications.'); }
        } elseif ($this->store->db->update('insight_preparation', $values, ['tenant_id' => $tenant, 'market_id' => $market, 'revision' => $revision]) !== 1) {
            throw new InsightException('Modification simultanée détectée. Copiez vos notes et rechargez la page.');
        }
    }
}
