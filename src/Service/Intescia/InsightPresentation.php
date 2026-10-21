<?php

declare(strict_types=1);
namespace App\Service\Intescia;

final class InsightPresentation
{
    public static function build(array $payload): array
    {
        $contract = $payload['enrichment']['detail']['contract'] ?? [];
        $op = $payload['followed']['dealOpportunity'] ?? [];
        $cities = $op['executionCities'] ?? $contract['executionCities'] ?? [];
        $locations = [];
        foreach (is_array($cities) ? $cities : [] as $city) {
            if (!is_string($city) || trim($city) === '') { continue; }
            $city = trim(strip_tags($city));
            $locations[$city] = ['name' => $city, 'map' => 'https://maps.google.com/maps?q='.rawurlencode($city).'&output=embed', 'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($city)];
        }
        $links = [];
        foreach (InsightNormalizer::flatten($payload) as [$path, $value]) {
            if (!self::safeUrl($value)) { continue; }
            // Keep technical endpoints in the full-data table, not among consultation links.
            if (str_contains($path, '/_links/') || parse_url($value, PHP_URL_HOST) === 'v1.api.intescia.dev') { continue; }
            $links[$value] = ['url' => $value, 'host' => parse_url($value, PHP_URL_HOST), 'source' => $path];
        }
        $description = $contract['completeObject'] ?? InsightNormalizer::translation($contract['translations'] ?? $op['translations'] ?? [], 'completeObject');
        return ['locations' => array_values($locations), 'links' => array_values($links), 'description' => is_string($description) ? strip_tags($description) : '', 'reference' => is_scalar($contract['reference'] ?? null) ? (string) $contract['reference'] : ''];
    }

    public static function safeUrl(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            && !parse_url($value, PHP_URL_USER) && !parse_url($value, PHP_URL_PASS);
    }
}
