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
        $coveredCities = [];
        foreach ($contract['executions'] ?? [] as $execution) {
            if (!is_array($execution)) continue;
            $city = trim(strip_tags((string) ($execution['city'] ?? '')));
            $address = trim(strip_tags((string) ($execution['address'] ?? '')));
            if ($city === '') continue;
            $name = $address !== '' ? $address.', '.$city : $city;
            $coveredCities[] = mb_strtolower($city);
            $locations[mb_strtolower($name)] = self::location($name, $address !== '' ? 'Adresse annoncée · emplacement à confirmer sur les plans' : 'Commune annoncée · localisation indicative');
        }
        foreach (is_array($cities) ? $cities : [] as $city) {
            if (!is_string($city) || trim($city) === '') continue;
            $city = trim(strip_tags($city));
            if (in_array(mb_strtolower($city), $coveredCities, true)) continue;
            $locations[mb_strtolower($city)] = self::location($city, 'Commune annoncée · localisation indicative');
        }
        $links = [];
        foreach (InsightNormalizer::flatten($payload) as [$path, $value]) {
            if (!self::safeUrl($value)) { continue; }
            // Keep technical endpoints in the full-data table, not among consultation links.
            if (str_contains($path, '/_links/') || parse_url($value, PHP_URL_HOST) === 'v1.api.intescia.dev') { continue; }
            $links[$value] = ['url' => $value, 'host' => parse_url($value, PHP_URL_HOST), 'source' => $path];
        }
        $description = $contract['completeObject'] ?? InsightNormalizer::translation($contract['translations'] ?? $op['translations'] ?? [], 'completeObject');
        if (!$description) $description = InsightNormalizer::translation($contract['translations'] ?? [], 'description');
        return ['locations' => array_values($locations), 'links' => array_values($links), 'description' => is_string($description) ? strip_tags($description) : '', 'analysis' => InsightAnalysis::build($payload), 'reference' => is_scalar($contract['reference'] ?? null) ? (string) $contract['reference'] : ''];
    }

    private static function location(string $name, string $precision): array
    {
        return ['name' => $name, 'precision' => $precision, 'map' => 'https://maps.google.com/maps?q='.rawurlencode($name).'&output=embed', 'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($name)];
    }

    public static function safeUrl(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            && !parse_url($value, PHP_URL_USER) && !parse_url($value, PHP_URL_PASS);
    }
}
