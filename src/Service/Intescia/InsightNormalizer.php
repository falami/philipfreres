<?php

declare(strict_types=1);
namespace App\Service\Intescia;

final class InsightNormalizer
{
    public static function translation(array $translations, string $field): string
    {
        foreach ($translations as $translation) {
            if (($translation['language'] ?? '') === 'FR' && !empty($translation[$field])) { return strip_tags((string) $translation[$field]); }
        }
        return strip_tags((string) ($translations[0][$field] ?? ''));
    }

    public static function deadline(mixed $value): ?string
    {
        // Never invent an offer deadline from a date-only publication or renewal date.
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) { return null; }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('Europe/Paris'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) { return null; }
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) { return null; }
    }

    public static function row(array $hit, array $cache): array
    {
        $op = $hit['dealOpportunity'] ?? [];
        $contract = $cache['detail']['contract'] ?? [];
        if (!preg_match('/^[a-f0-9-]{36}$/D', $op['id'] ?? '')) { throw new InsightException('Identifiant de marché absent ou invalide.'); }
        // response is the documented offer deadline. deadline has broader semantics and is NOT used.
        $response = array_key_exists('response', $op) ? $op['response'] : null;
        $isLot = str_contains($op['dealOpportunityResultType'] ?? '', 'LOT');
        if (!array_key_exists('response', $op) && !$isLot) { $response = $contract['response'] ?? null; }
        if (!array_key_exists('response', $op) && $isLot) {
            foreach ($cache['lots'] ?? [] as $lot) { if (($lot['id'] ?? null) === $op['id']) { $response = $lot['response'] ?? null; break; } }
        }
        if (!empty($contract['cancellation']) || in_array($op['state'] ?? $contract['state'] ?? '', ['AWARDED', 'NOT_AWARDED', 'EXPIRED', 'DISMISSED', 'UNSUCCESSFUL', 'UNSUCCESSFUL_DISMISSED'], true) || ($op['progress'] ?? $contract['progress'] ?? '') === 'PROJECT_CANCELED') { $response = null; }
        return [
            'remote_id' => $op['id'],
            'title' => self::translation($op['translations'] ?? $contract['translations'] ?? [], 'object') ?: 'Marché sans intitulé',
            'buyer' => self::translation($op['principal']['translations'] ?? $contract['principal']['translations'] ?? [], 'name'),
            'deadline' => self::deadline($response),
        ];
    }

    /** Every leaf, including unknown future fields and empty containers, remains inspectable. */
    public static function flatten(mixed $data, string $prefix = ''): array
    {
        if (!is_array($data) || $data === []) {
            return [[$prefix ?: '/', is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]];
        }
        $rows = [];
        foreach ($data as $key => $value) {
            $path = $prefix.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            array_push($rows, ...self::flatten($value, $path));
        }
        return $rows;
    }
}
