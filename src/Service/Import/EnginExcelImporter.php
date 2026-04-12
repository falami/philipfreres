<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Entite;
use App\Entity\Engin;
use App\Entity\Utilisateur;
use App\Enum\EnginType;
use App\Repository\EnginRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EnginExcelImporter
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly EnginRepository $enginRepo,
  ) {}

  /**
   * @return array{
   *   imported:int,
   *   updated:int,
   *   skipped:int,
   *   total:int,
   *   headerRow:int|null,
   *   errors:array<int,string>,
   *   warnings:array<int,string>
   * }
   */
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
        'errors' => [0 => "Impossible de détecter la ligne d’entêtes (ex: nom / type / immatriculation)."],
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

    // Champs mini pour un engin (au moins nom OU immatriculation + type idéalement)
    if (!isset($colMap['nom']) && !isset($colMap['immatriculation'])) {
      return [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => 0,
        'headerRow' => $headerRow,
        'errors' => [0 => "Colonne NOM ou IMMATRICULATION introuvable (au moins une des deux)."],
        'warnings' => [],
      ];
    }

    // 3) Compteurs
    $imported = 0;
    $updated  = 0;
    $skipped  = 0;
    $total    = 0;
    $errors   = [];
    $warnings = [];

    $batchFlush = 50;
    $processedSinceFlush = 0;

    // 4) Détection CSV
    $originalName = (string) ($file->getClientOriginalName() ?: '');
    $ext = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $isCsv = ($reader instanceof CsvReader) || ($ext === 'csv') || str_ends_with(mb_strtolower($path), '.csv');

    // ==========================================================
    // ✅ CSV : lecture FULL
    // ==========================================================
    if ($isCsv) {
      $spreadsheetCsv = $this->createReader($path)->load($path);
      $sheetCsv = $spreadsheetCsv->getSheet(0);

      $highestRow = (int) $sheetCsv->getHighestRow();
      $highestCol = Coordinate::columnIndexFromString((string) $sheetCsv->getHighestColumn());

      for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
        if ($this->isRowEmpty($sheetCsv, $row, $highestCol)) {
          continue;
        }

        $total++;

        try {
          $data = $this->extractRowData($sheetCsv, $row, $colMap);

          $nom = $this->normalizeString($data['nom'] ?? null);
          $immat = $this->normalizeImmatriculation($data['immatriculation'] ?? null);
          $type = $this->parseEnginType($data['type'] ?? null);

          if (!$nom && !$immat) {
            $skipped++;
            $warnings[$row] = "Ligne $row ignorée : nom et immatriculation vides.";
            continue;
          }

          if ($type === null) {
            // tu peux le rendre obligatoire si tu veux
            $warnings[$row] = ($warnings[$row] ?? '') . " Ligne $row : type non reconnu → valeur par défaut conservée.";
          }

          $engin = $this->findExistingEngin($entite, $immat, $nom);

          $isNew = false;
          if (!$engin) {
            $isNew = true;
            $engin = new Engin();
            $engin->setEntite($entite);
            $engin->setCreateur($createur);
          }

          $this->applyData($engin, $data, $type);

          // anti-doublon strict (si immatriculation renseignée)
          if ($immat) {
            $existingSameImmat = $this->enginRepo->findOneBy(['entite' => $entite, 'immatriculation' => $immat]);
            if ($existingSameImmat && $existingSameImmat->getId() !== $engin->getId()) {
              $skipped++;
              $warnings[$row] = "Ligne $row ignorée : immatriculation déjà utilisée dans cette entité.";
              continue;
            }
          }

          $this->em->persist($engin);

          if ($isNew) $imported++;
          else $updated++;

          $processedSinceFlush++;
          if ($processedSinceFlush >= $batchFlush) {
            $this->em->flush();
            $processedSinceFlush = 0;
          }
        } catch (\Throwable $e) {
          $skipped++;
          $errors[$row] = "Ligne $row : " . $e->getMessage();
        }
      }

      if ($processedSinceFlush > 0) {
        $this->em->flush();
      }

      $spreadsheetCsv->disconnectWorksheets();
      unset($spreadsheetCsv);

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

    // ==========================================================
    // ✅ XLSX/XLS : chunking
    // ==========================================================
    $spreadsheetAll = $this->loadAllReadDataOnly($reader, $path);
    $sheetAll = $spreadsheetAll->getSheet(0);

    $highestRow = (int) $sheetAll->getHighestRow();
    $highestColAll = Coordinate::columnIndexFromString((string) $sheetAll->getHighestColumn());

    $spreadsheetAll->disconnectWorksheets();
    unset($spreadsheetAll);

    $chunkSize = 200;

    for ($start = $headerRow + 1; $start <= $highestRow; $start += $chunkSize) {
      $end = min($highestRow, $start + $chunkSize - 1);

      $chunkReader = $this->createReader($path);
      $chunkReader->setReadDataOnly(true);
      $chunkReader->setReadFilter(new ChunkReadFilter($start, $end, $sheetName));

      $chunkSpreadsheet = $chunkReader->load($path);
      $chunkSheet = $chunkSpreadsheet->getSheet(0);

      for ($row = $start; $row <= $end; $row++) {
        if ($this->isRowEmpty($chunkSheet, $row, $highestColAll)) {
          continue;
        }

        $total++;

        try {
          $data = $this->extractRowData($chunkSheet, $row, $colMap);

          $nom = $this->normalizeString($data['nom'] ?? null);
          $immat = $this->normalizeImmatriculation($data['immatriculation'] ?? null);
          $type = $this->parseEnginType($data['type'] ?? null);

          if (!$nom && !$immat) {
            $skipped++;
            $warnings[$row] = "Ligne $row ignorée : nom et immatriculation vides.";
            continue;
          }

          $engin = $this->findExistingEngin($entite, $immat, $nom);

          $isNew = false;
          if (!$engin) {
            $isNew = true;
            $engin = new Engin();
            $engin->setEntite($entite);
            $engin->setCreateur($createur);
          }

          $this->applyData($engin, $data, $type);

          if ($immat) {
            $existingSameImmat = $this->enginRepo->findOneBy(['entite' => $entite, 'immatriculation' => $immat]);
            if ($existingSameImmat && $existingSameImmat->getId() !== $engin->getId()) {
              $skipped++;
              $warnings[$row] = "Ligne $row ignorée : immatriculation déjà utilisée dans cette entité.";
              continue;
            }
          }

          $this->em->persist($engin);

          if ($isNew) $imported++;
          else $updated++;

          $processedSinceFlush++;
          if ($processedSinceFlush >= $batchFlush) {
            $this->em->flush();
            $processedSinceFlush = 0;
          }
        } catch (\Throwable $e) {
          $skipped++;
          $errors[$row] = "Ligne $row : " . $e->getMessage();
        }
      }

      $chunkSpreadsheet->disconnectWorksheets();
      unset($chunkSpreadsheet);
    }

    if ($processedSinceFlush > 0) {
      $this->em->flush();
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
  // Reader / CSV auto
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

    $needles = [
      'nom',
      'name',
      'engin',
      'immatriculation',
      'immat',
      'plaque',
      'type',
      'categorie'
    ];

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
      'nom' => ['nom', 'engin', 'name', 'designation', 'désignation'],
      'type' => ['type', 'categorie', 'catégorie', 'engin type'],
      'annee' => ['annee', 'année', 'year', 'an'],
      'immatriculation' => ['immatriculation', 'immat', 'plaque', 'plate', 'registration'],
      'photoCouverture' => ['photo', 'photo couverture', 'photo_couverture', 'image', 'image couverture'],
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
      'nom' => $get('nom'),
      'type' => $get('type'),
      'annee' => $get('annee'),
      'immatriculation' => $get('immatriculation'),
      'photoCouverture' => $get('photoCouverture'),
    ];
  }

  // -------------------------------------------------------------------
  // Business rules
  // -------------------------------------------------------------------

  private function findExistingEngin(Entite $entite, ?string $immat, ?string $nom): ?Engin
  {
    if ($immat) {
      $e = $this->enginRepo->findOneBy(['entite' => $entite, 'immatriculation' => $immat]);
      if ($e) return $e;
    }

    if ($nom) {
      // fallback : nom exact dans l’entité (si tu veux plus strict, enlève)
      $e = $this->enginRepo->findOneBy(['entite' => $entite, 'nom' => $nom]);
      if ($e) return $e;
    }

    return null;
  }

  private function applyData(Engin $e, array $data, ?EnginType $type): void
  {
    if (!empty($data['nom'])) {
      $e->setNom((string) $data['nom']);
    }

    if ($type instanceof EnginType) {
      $e->setType($type);
    }

    if (array_key_exists('annee', $data)) {
      $year = $this->parseYear($data['annee'] ?? null);
      if ($year !== null) {
        $e->setAnnee($year);
      }
    }

    if (array_key_exists('immatriculation', $data)) {
      $immat = $this->normalizeImmatriculation($data['immatriculation'] ?? null);
      $e->setImmatriculation($immat);
    }

    if (array_key_exists('photoCouverture', $data)) {
      $photo = $this->normalizeString($data['photoCouverture'] ?? null);
      $e->setPhotoCouverture($photo);
    }
  }

  private function parseYear(?string $v): ?int
  {
    $v = trim((string) $v);
    if ($v === '') return null;

    $v = preg_replace('/[^\d]/', '', $v) ?: $v;
    if (!ctype_digit($v)) return null;

    $year = (int) $v;
    if ($year < 1900 || $year > ((int) date('Y') + 1)) return null;
    return $year;
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
    // on garde tirets, lettres, chiffres
    $s = preg_replace('/[^A-Z0-9\-]/', '', $s) ?: $s;

    return $s !== '' ? $s : null;
  }

  private function parseEnginType(?string $raw): ?EnginType
  {
    $s = strtoupper(trim((string) $raw));
    if ($s === '') return null;

    $s = str_replace([' ', '-', 'é', 'è', 'ê', 'à', 'ç'], ['_', '_', 'E', 'E', 'E', 'A', 'C'], $s);
    $s = preg_replace('/[^A-Z0-9_]/', '', $s) ?: $s;

    // 1) Essai direct : valeur enum
    try {
      return EnginType::from($s);
    } catch (\Throwable) {
    }

    // 2) Synonymes lisibles (à adapter si tu as d’autres types)
    $map = [
      'CHARGEUSE' => ['CHARGEUSE', 'CHARGEUR', 'LOADER'],
      'PELLE' => ['PELLE', 'EXCAVATRICE', 'EXCAVATOR'],
      'TRACTEUR' => ['TRACTEUR', 'TRACTOR'],
      'CAMION' => ['CAMION', 'TRUCK'],
    ];

    foreach ($map as $enumValue => $aliases) {
      foreach ($aliases as $a) {
        if ($s === $a) {
          try {
            return EnginType::from($enumValue);
          } catch (\Throwable) {
          }
        }
      }
    }

    return null;
  }
}
