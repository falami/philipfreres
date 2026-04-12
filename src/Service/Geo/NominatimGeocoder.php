<?php
// src/Service/Geo/NominatimGeocoder.php

declare(strict_types=1);

namespace App\Service\Geo;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NominatimGeocoder
{
  public function __construct(
    private readonly HttpClientInterface $http,
    private readonly string $userAgent = 'PhilipFreres/1.0 (contact: admin@local)'
  ) {}

  /**
   * @return array{lat:float|null,lng:float|null,display_name:?string,provider:?string,confidence:?int}|null
   */
  public function geocode(string $address): ?array
  {
    $address = trim($address);
    if ($address === '') return null;

    // Nominatim nécessite un User-Agent explicite
    $resp = $this->http->request('GET', 'https://nominatim.openstreetmap.org/search', [
      'headers' => [
        'User-Agent' => $this->userAgent,
        'Accept' => 'application/json',
      ],
      'query' => [
        'q' => $address,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 0,
      ],
      'timeout' => 20,
    ]);

    if ($resp->getStatusCode() !== 200) {
      return null;
    }

    $data = $resp->toArray(false);
    if (!is_array($data) || !isset($data[0])) return null;

    $row = $data[0];

    $lat = isset($row['lat']) ? (float) $row['lat'] : null;
    $lng = isset($row['lon']) ? (float) $row['lon'] : null;

    return [
      'lat' => $lat,
      'lng' => $lng,
      'display_name' => isset($row['display_name']) ? (string) $row['display_name'] : null,
      'provider' => 'nominatim',
      'confidence' => isset($row['importance']) ? (int) round(((float)$row['importance']) * 100) : null,
    ];
  }
}
