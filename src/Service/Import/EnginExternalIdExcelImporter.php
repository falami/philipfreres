<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Entite;
use App\Entity\Engin;
use App\Entity\EnginExternalId;
use App\Entity\Utilisateur;
use App\Enum\ExternalProvider;
use App\Repository\EnginExternalIdRepository;
use App\Repository\EnginRepository;
use App\Service\Carburant\FuelKey;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EnginExternalIdExcelImporter
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly EnginRepository $enginRepo,
    private readonly EnginExternalIdRepository $extRepo,
  ) {}

  public function import(Entite $entite, Utilisateur $createur, UploadedFile $file): array
  {
    @ini_set('max_execution_time', '300');
    @set_time_limit(300);

    $path = $file->getPathname();
    $reader = $this->createReader($path);

    // 1) Détection entêtes
    $headerRow = $this->detectHeaderRow($reader, $path, maxScanRows: 80);
    if ($headerRow === null) {
      return [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => 0,
        'headerRow' => null,
        'errors' => [0 => "Impossible de détecter la ligne d’entêtes (provider/value/immatriculation)."],
        'warnings' => [],
      ];
    }

    // 2) Mapping colonnes
    $sheetName = null;
    $spreadsheetHeader = $this->loadRange($reader, $path, 1, $headerRow, $sheetName);
    $sheetHeader = $spreadsheetHeader->getSheet(0);

    $highestColHeader = Coordinate::columnIndexFromString((string) $sheetHeader->getHighestColumn());
    $headers = $this->readRowValues($sheetHeader, $headerRow, $highestColHeader);
    $colMap = $this->buildColumnMap($headers);

    $spreadsheetHeader->disconnectWorksheets();
    unset($spreadsheetHeader);

    if (!isset($colMap['provider']) || !isset($colMap['value'])) {
      return [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => 0,
        'headerRow' => $headerRow,
        'errors' => [0 => "Colonnes obligatoires introuvables : provider + value."],
        'warnings' => [],
      ];
    }

    // ============================
    // 🔥 INDEX DES ENGINS (clé mémoire)
    // ============================
    $enginById = [];
    $enginByImmatKey = [];
    $enginByNameKey = [];

    foreach ($this->enginRepo->findBy(['entite' => $entite]) as $e) {
      if ($e->getId()) {
        $enginById[(int) $e->getId()] = $e;
      }

      $k = $this->immatKey($e->getImmatriculation());
      if ($k) $enginByImmatKey[$k] = $e;

      $nk = $this->nameKey($e->getNom());
      if ($nk) $enginByNameKey[$nk] = $e;
    }

    $imported = 0;
    $updated  = 0;
    $skipped  = 0;
    $total    = 0;
    $errors   = [];
    $warnings = [];

    $batchFlush = 100;
    $processedSinceFlush = 0;

    $originalName = (string) ($file->getClientOriginalName() ?: '');
    $ext = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $isCsv = ($reader instanceof CsvReader) || ($ext === 'csv');

    // ==========================================================
    // CSV
    // ==========================================================
    if ($isCsv) {
      $spreadsheet = $this->createReader($path)->load($path);
      $sheet = $spreadsheet->getSheet(0);

      $highestRow = (int) $sheet->getHighestRow();
      $highestCol = Coordinate::columnIndexFromString((string) $sheet->getHighestColumn());

      for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
        if ($this->isRowEmpty($sheet, $row, $highestCol)) continue;
        $total++;

        try {
          $data = $this->extractRowData($sheet, $row, $colMap);

          $engin = $this->resolveEngin(
            $entite,
            $data,
            $enginById,
            $enginByImmatKey,
            $enginByNameKey
          );

          if (!$engin) {
            $skipped++;
            $warnings[$row] = "Engin introuvable (immat=" . ($data['immatriculation'] ?? '') . ")";
            continue;
          }

          $provider = $this->parseProvider($data['provider'] ?? null);
          if (!$provider) {
            $skipped++;
            $warnings[$row] = "Provider invalide.";
            continue;
          }

          $value = $this->normalizeExternalValue($data['value'] ?? null);
          if (!$value) {
            $skipped++;
            $warnings[$row] = "Value vide.";
            continue;
          }

          $active = $this->parseBool($data['active'] ?? null, true);
          $note   = $this->normalizeString($data['note'] ?? null);

          $extId = $this->extRepo->findOneBy([
            'engin' => $engin,
            'provider' => $provider,
            'value' => $value,
          ]);

          $isNew = false;
          if (!$extId) {
            $isNew = true;
            $extId = new EnginExternalId($provider, $value);
            $extId->setEngin($engin);
          }

          if ($note !== null) $extId->setNote($note);

          if (!$active && $extId->isActive()) {
            $extId->disable($note);
          }

          $this->em->persist($extId);

          if ($isNew) $imported++;
          else $updated++;

          $processedSinceFlush++;
          if ($processedSinceFlush >= $batchFlush) {
            $this->em->flush();
            $processedSinceFlush = 0;
          }
        } catch (\Throwable $e) {
          $skipped++;
          $errors[$row] = $e->getMessage();
        }
      }

      if ($processedSinceFlush > 0) $this->em->flush();

      $spreadsheet->disconnectWorksheets();
    }

    return [
      'imported' => $imported,
      'updated' => $updated,
      'skipped' => $skipped,
      'total' => $total,
      'headerRow' => $headerRow,
      'errors' => $errors,
      'warnings' => $warnings,
    ];
  }

  // -------------------------------------------------------------------
  // Helpers reader / header / extraction
  // -------------------------------------------------------------------

  private function createReader(string $path): IReader
  {
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);

    if ($reader instanceof CsvReader) {
      $delimiter = $this->detectCsvDelimiter($path);
      $encoding  = $this->detectCsvEncoding($path);

      $reader->setDelimiter($delimiter);
      $reader->setEnclosure('"');
      $reader->setEscapeCharacter('\\');
      $reader->setInputEncoding($encoding);
      $reader->setSheetIndex(0);
    }

    return $reader;
  }

  private function detectCsvDelimiter(string $path): string
  {
    $line = '';
    $fh = @fopen($path, 'rb');
    if ($fh) {
      $line = (string) fgets($fh, 4096);
      fclose($fh);
    }

    $candidates = [';', ',', "\t", '|'];
    $best = ';';
    $bestCount = 0;

    foreach ($candidates as $d) {
      $count = substr_count($line, $d);
      if ($count > $bestCount) {
        $bestCount = $count;
        $best = $d;
      }
    }
    return $best;
  }

  private function detectCsvEncoding(string $path): string
  {
    $raw = @file_get_contents($path, false, null, 0, 20000);
    if (!is_string($raw) || $raw === '') return 'UTF-8';
    if (str_starts_with($raw, "\xEF\xBB\xBF")) return 'UTF-8';
    $enc = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    return $enc ?: 'UTF-8';
  }

  private function loadRange(IReader $reader, string $path, int $start, int $end, ?string $sheetName): \PhpOffice\PhpSpreadsheet\Spreadsheet
  {
    $r = $this->createReader($path);
    $r->setReadFilter(new ChunkReadFilter($start, $end, $sheetName));
    return $r->load($path);
  }

  private function loadAllReadDataOnly(IReader $reader, string $path): \PhpOffice\PhpSpreadsheet\Spreadsheet
  {
    $r = $this->createReader($path);
    return $r->load($path);
  }

  private function detectHeaderRow(IReader $reader, string $path, int $maxScanRows = 80): ?int
  {
    $scan = $this->loadRange($reader, $path, 1, $maxScanRows, null);
    $sheet = $scan->getSheet(0);
    $highestCol = Coordinate::columnIndexFromString((string) $sheet->getHighestColumn());

    $needles = ['provider', 'value', 'engin_id', 'immatriculation', 'nom'];

    for ($row = 1; $row <= $maxScanRows; $row++) {
      $values = $this->readRowValues($sheet, $row, $highestCol);
      $norm = array_map(fn($v) => $this->normHeader((string) $v), $values);

      foreach ($norm as $cell) {
        foreach ($needles as $n) {
          if ($cell === $this->normHeader($n)) {
            $scan->disconnectWorksheets();
            return $row;
          }
        }
      }
    }

    $scan->disconnectWorksheets();
    return null;
  }

  private function immatKey(?string $s): ?string
  {
    $s = strtoupper(trim((string) $s));
    if ($s === '') return null;

    // On garde uniquement lettres et chiffres
    $s = preg_replace('/[^A-Z0-9]/', '', $s);
    if ($s === null || $s === '') return null;

    // Format FR moderne : AA123AA (7 caractères)
    if (strlen($s) === 7) {
      $part1 = substr($s, 0, 2);
      $part2 = substr($s, 2, 3);
      $part3 = substr($s, 5, 2);

      // Vérification basique du pattern
      if (
        ctype_alpha($part1) &&
        ctype_digit($part2) &&
        ctype_alpha($part3)
      ) {
        return $part1 . '-' . $part2 . '-' . $part3;
      }
    }

    // Sinon on retourne juste la version nettoyée
    return $s;
  }

  private function nameKey(?string $s): ?string
  {
    $s = trim((string) $s);
    if ($s === '') return null;

    $s = mb_strtolower($s);
    $s = preg_replace('/\s+/', ' ', $s) ?: $s;

    return $s !== '' ? $s : null;
  }



  /** @return array<int,string> */
  private function readRowValues(Worksheet $sheet, int $row, int $highestCol): array
  {
    $out = [];
    for ($col = 1; $col <= $highestCol; $col++) {
      $out[$col] = trim((string) $this->cell($sheet, $col, $row)->getFormattedValue());
    }
    return $out;
  }

  private function isRowEmpty(Worksheet $sheet, int $row, int $highestCol): bool
  {
    for ($col = 1; $col <= $highestCol; $col++) {
      $v = trim((string) $this->cell($sheet, $col, $row)->getFormattedValue());
      if ($v !== '') return false;
    }
    return true;
  }

  private function normHeader(string $h): string
  {
    $h = trim(mb_strtolower($h));
    $h = str_replace(['’', "'", "`"], '', $h);
    $h = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h) ?: $h;
    $h = preg_replace('/\s+/', ' ', $h) ?: $h;
    $h = preg_replace('/[^a-z0-9 ]/', ' ', $h) ?: $h;
    $h = preg_replace('/\s+/', ' ', $h) ?: $h;
    return trim($h);
  }

  /** @param array<int,string> $headers */
  private function buildColumnMap(array $headers): array
  {
    $aliases = [
      // engin lookup
      'engin_id' => ['engin_id', 'id engin', 'id'],
      'immatriculation' => ['immatriculation', 'immat', 'plaque', 'registration', 'plate'],
      'nom' => ['nom', 'engin', 'name', 'designation', 'désignation'],

      // ext id
      'provider' => ['provider', 'fournisseur', 'source', 'presta', 'prestataire'],
      'value' => ['value', 'external id', 'external_id', 'id externe', 'id_externe', 'code', 'ref'],

      // optional
      'active' => ['active', 'actif', 'enabled', 'enable'],
      'note' => ['note', 'commentaire', 'comment'],
    ];

    $map = [];
    foreach ($headers as $colIndex => $header) {
      $h = $this->normHeader($header);
      if ($h === '') continue;

      foreach ($aliases as $field => $names) {
        foreach ($names as $n) {
          if ($h === $this->normHeader($n)) {
            $map[$field] = (int) $colIndex;
            break 2;
          }
        }
      }
    }
    return $map;
  }

  private function cell(Worksheet $sheet, int $col, int $row): Cell
  {
    return $sheet->getCell([$col, $row]);
  }

  private function extractRowData(Worksheet $sheet, int $row, array $colMap): array
  {
    $get = function (string $field) use ($sheet, $row, $colMap): ?string {
      if (!isset($colMap[$field])) return null;
      $col  = (int) $colMap[$field];
      $cell = $this->cell($sheet, $col, $row);
      $raw = $cell->getValue();
      if ($raw === null) return null;
      return trim((string) $cell->getFormattedValue());
    };

    return [
      'engin_id' => $get('engin_id'),
      'immatriculation' => $get('immatriculation'),
      'nom' => $get('nom'),
      'provider' => $get('provider'),
      'value' => $get('value'),
      'active' => $get('active'),
      'note' => $get('note'),
    ];
  }

  // -------------------------------------------------------------------
  // Business
  // -------------------------------------------------------------------

  private function resolveEngin(
    Entite $entite,
    array $data,
    array $enginById,
    array $enginByImmatKey,
    array $enginByNameKey
  ): ?Engin {
    // 1) engin_id
    $idRaw = trim((string) ($data['engin_id'] ?? ''));
    if ($idRaw !== '' && ctype_digit($idRaw)) {
      $id = (int) $idRaw;
      if (isset($enginById[$id])) return $enginById[$id];
    }

    // 2) immatriculation (robuste)
    $immatKey = $this->immatKey($data['immatriculation'] ?? null);
    if ($immatKey && isset($enginByImmatKey[$immatKey])) {
      return $enginByImmatKey[$immatKey];
    }

    // 3) nom (fallback)
    $nameKey = $this->nameKey($data['nom'] ?? null);
    if ($nameKey && isset($enginByNameKey[$nameKey])) {
      return $enginByNameKey[$nameKey];
    }

    return null;
  }

  private function parseProvider(?string $raw): ?ExternalProvider
  {
    $s = trim((string) $raw);
    if ($s === '') return null;

    // Normalisation agressive
    $s = mb_strtolower($s);
    $s = str_replace(["\u{00A0}", ' '], '', $s); // espaces + espace insécable
    $s = str_replace(['-', '_', '.', '/', '\\'], '', $s);

    // Tolérances / alias
    $map = [
      'alx' => 'alx',
      'cartealx' => 'alx',

      'total' => 'total',
      'totalenergies' => 'total',
      'totalenergie' => 'total',
      'totalenergiess' => 'total',
      'totalenergiesfr' => 'total',

      'edenred' => 'edenred',
      'edenredticketcar' => 'edenred',
      'ticketcar' => 'edenred',
      'ticketcarte' => 'edenred',
    ];

    $s = $map[$s] ?? $s;

    return ExternalProvider::tryFrom($s);
  }

  private function normalizeExternalValue(?string $v): ?string
  {
    $v = trim((string) $v);
    if ($v === '') return null;

    $norm = FuelKey::norm($v);
    $norm = $norm ?? $v;
    $norm = trim($norm);

    return $norm !== '' ? $norm : null;
  }

  private function parseBool(?string $v, bool $default = true): bool
  {
    $s = mb_strtolower(trim((string) $v));
    if ($s === '') return $default;

    if (in_array($s, ['1', 'true', 'vrai', 'yes', 'oui', 'y', 'on'], true)) return true;
    if (in_array($s, ['0', 'false', 'faux', 'no', 'non', 'n', 'off'], true)) return false;

    return $default;
  }

  private function normalizeString(?string $s): ?string
  {
    $s = trim((string) $s);
    return $s !== '' ? $s : null;
  }

  private function normalizeImmatriculation(?string $s): ?string
  {
    $s = strtoupper(trim((string) $s));
    if ($s === '') return null;

    $s = preg_replace('/\s+/', '', $s) ?: $s;
    $s = preg_replace('/[^A-Z0-9\-]/', '', $s) ?: $s;

    return $s !== '' ? $s : null;
  }
}
