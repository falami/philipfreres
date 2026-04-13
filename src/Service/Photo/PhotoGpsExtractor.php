<?php

namespace App\Service\Photo;

final class PhotoGpsExtractor
{
  public function extractFromFile(string $absolutePath): ?array
  {
    if (!is_file($absolutePath)) {
      return null;
    }

    if (!function_exists('exif_read_data')) {
      return null;
    }

    try {
      $exif = @exif_read_data($absolutePath, 'GPS', true);
      if (!$exif || empty($exif['GPS'])) {
        return null;
      }

      $gps = $exif['GPS'];

      if (
        empty($gps['GPSLatitude']) ||
        empty($gps['GPSLatitudeRef']) ||
        empty($gps['GPSLongitude']) ||
        empty($gps['GPSLongitudeRef'])
      ) {
        return null;
      }

      $lat = $this->gpsToDecimal($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
      $lng = $this->gpsToDecimal($gps['GPSLongitude'], $gps['GPSLongitudeRef']);

      if ($lat === null || $lng === null) {
        return null;
      }

      return [
        'latitude' => number_format($lat, 7, '.', ''),
        'longitude' => number_format($lng, 7, '.', ''),
      ];
    } catch (\Throwable) {
      return null;
    }
  }

  private function gpsToDecimal(array $coordinate, string $hemisphere): ?float
  {
    if (count($coordinate) < 3) {
      return null;
    }

    $degrees = $this->fractionToFloat($coordinate[0]);
    $minutes = $this->fractionToFloat($coordinate[1]);
    $seconds = $this->fractionToFloat($coordinate[2]);

    if ($degrees === null || $minutes === null || $seconds === null) {
      return null;
    }

    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

    if (in_array(strtoupper($hemisphere), ['S', 'W'], true)) {
      $decimal *= -1;
    }

    return $decimal;
  }

  private function fractionToFloat(string $value): ?float
  {
    if (str_contains($value, '/')) {
      [$num, $den] = array_pad(explode('/', $value, 2), 2, null);
      if (!$num || !$den || (float) $den === 0.0) {
        return null;
      }

      return (float) $num / (float) $den;
    }

    return is_numeric($value) ? (float) $value : null;
  }
}
