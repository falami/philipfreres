<?php
// src/Service/Import/TransactionCarteAlxExcelImporter.php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Entite;
use App\Entity\TransactionCarteAlx;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use App\Service\Import\ChunkReadFilter;
use App\Enum\ExternalProvider;

final class TransactionCarteAlxExcelImporter
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly \App\Service\Carburant\TransactionLinkResolver $resolver,
  ) {}

  /**
   * @return array{imported:int, skipped:int, errors:array<int,string>}
   */
  public function import(Entite $entite, UploadedFile $file): array
  {
    @ini_set('max_execution_time', '300');
    @set_time_limit(300);

    $path = $file->getPathname();
    $filename = $file->getClientOriginalName() ?: 'import.xlsx';

    $repo = $this->em->getRepository(TransactionCarteAlx::class);

    $imported = 0;
    $skipped = 0;
    $errors = [];

    // ===== 0) Reader =====
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $reader->setReadEmptyCells(false);

    // feuille la plus probable (ton fichier: Export4(16))
    $sheetName = $this->pickBestSheetName($path);

    if ($sheetName !== null) {
      $reader->setLoadSheetsOnly([$sheetName]);
    }

    // ===== 1) Charge uniquement 250 premières lignes pour détecter entêtes =====
    $reader->setReadFilter(new ChunkReadFilter(1, 250, $sheetName));
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();

    [$headerRow, $colMap] = $this->detectHeaderRowAndMap($sheet);

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    gc_collect_cycles();

    if ($headerRow === null) {
      return [
        'imported' => 0,
        'skipped' => 0,
        'errors' => ["Entêtes introuvables : le fichier ne ressemble pas au format ALX attendu."],
      ];
    }

    // ===== 2) Détecter maxRow sans charger tout =====
    $maxRow = $this->detectMaxRowByChunks($reader, $path, $headerRow, $colMap, $sheetName);

    if ($maxRow <= $headerRow) {
      return [
        'imported' => 0,
        'skipped' => 0,
        'errors' => ["Aucune ligne de données détectée après les entêtes."],
      ];
    }

    // ===== 3) Import par chunks =====
    $chunkSize = 1000;
    $batchSize = 250;

    $seenKeys = [];

    try {
      $this->em->beginTransaction();

      for ($start = $headerRow + 1; $start <= $maxRow; $start += $chunkSize) {
        $end = min($start + $chunkSize - 1, $maxRow);

        $reader->setReadFilter(new ChunkReadFilter($start, $end, $sheetName));
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $entiteRef = $this->em->getReference(Entite::class, $entite->getId());
        $batch = 0;

        for ($r = $start; $r <= $end; $r++) {
          if ($this->isRowEmpty($sheet, $r, $colMap)) {
            continue;
          }

          try {
            $row = $this->readRow($sheet, $r, $colMap);
            $importKey = $this->buildImportKey($entite->getId(), $row);

            // doublon fichier
            if (isset($seenKeys[$importKey])) {
              $skipped++;
              continue;
            }
            $seenKeys[$importKey] = true;

            // doublon base
            $exists = $repo->findOneBy(['entite' => $entiteRef, 'importKey' => $importKey]);
            if ($exists) {
              $skipped++;
              continue;
            }

            $t = (new TransactionCarteAlx())
              ->setEntite($entiteRef)
              ->setSourceFilename($filename)
              ->setSourceRow($r)
              ->setImportKey($importKey);

            // parsing
            $t->setJournee($this->toDateImmutable($row['journee'] ?? null));
            $t->setHoraire($this->toTimeImmutable($row['horaire'] ?? null));

            $this->setIf($t, 'vehicule', $this->toStringClean($row['vehicule'] ?? null));
            $this->setIf($t, 'codeVeh', $this->toStringClean($row['code_veh'] ?? null));
            $this->setIf($t, 'codeAgent', $this->toStringClean($row['code_ag'] ?? null));
            $this->setIf($t, 'agent', $this->toStringClean($row['agent'] ?? null));

            if (($op = $this->toInt($row['operation'] ?? null)) !== null) $t->setOperation($op);
            if (($cu = $this->toInt($row['cuve'] ?? null)) !== null) $t->setCuve($cu);

            $t->setQuantite($this->toDecimalString($row['quantite'] ?? null, 3));
            $t->setPrixUnitaire($this->toDecimalString($row['prix_unitaire'] ?? null, 4));
            // compteur peut être grand, on le stocke en DECIMAL(12,0) string
            $t->setCompteur($this->toDecimalString($row['compteur'] ?? null, 0));
            $t->setProvider(ExternalProvider::ALX);

            try {
              $this->resolver->resolveAlx($entite, $t, false);
            } catch (\Throwable $e) {
              $origin = $this->firstNonVendorFrame($e);
              $errors[] =
                "Ligne {$r} (link): " . $e->getMessage()
                . ($origin ? " | {$origin}" : "");

              // si tu veux aussi le message précédent (souvent utile)
              if ($e->getPrevious()) {
                $prev = $e->getPrevious();
                $originPrev = $this->firstNonVendorFrame($prev);
                $errors[] = "↳ Previous: " . $prev->getMessage() . ($originPrev ? " | {$originPrev}" : "");
              }
            }

            $this->em->persist($t);
            $imported++;
            $batch++;

            if ($batch >= $batchSize) {
              $this->em->flush();
              $this->em->clear(TransactionCarteAlx::class);
              $entiteRef = $this->em->getReference(Entite::class, $entite->getId());
              $batch = 0;
            }
          } catch (\Throwable $e) {
            $errors[] = "Ligne {$r}: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine();
            $skipped++;
          }
        }

        $this->em->flush();
        $this->em->clear(TransactionCarteAlx::class);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();
      }

      $this->em->commit();
    } catch (\Throwable $e) {
      $this->em->rollback();
      $errors[] = "Import interrompu: " . $e->getMessage();
    }

    return compact('imported', 'skipped', 'errors');
  }


  private function firstNonVendorFrame(\Throwable $e): ?string
  {
    foreach ($e->getTrace() as $t) {
      $file = $t['file'] ?? null;
      $line = $t['line'] ?? null;
      if (!$file || !$line) continue;

      // ignore vendor + cache
      if (str_contains($file, '/vendor/')) continue;
      if (str_contains($file, '/var/cache/')) continue;

      return $file . ':' . $line;
    }

    // fallback: au moins le fichier courant
    $file = $e->getFile();
    if ($file && !str_contains($file, '/vendor/')) {
      return $file . ':' . $e->getLine();
    }

    return null;
  }

  private function pickBestSheetName(string $path): ?string
  {
    try {
      $reader = $this->makeSheetListingReader($path);
      $names = $reader->listWorksheetNames($path);

      if (!$names) return null;

      // préfère une feuille "Export"
      foreach ($names as $n) {
        if (stripos((string) $n, 'export') !== false) {
          return (string) $n;
        }
      }

      return (string) $names[0];
    } catch (\Throwable) {
      return null;
    }
  }

  /**
   * Retourne un reader qui sait lister les feuilles (compatible versions).
   */
  private function makeSheetListingReader(string $path): BaseReader
  {
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

    return match ($ext) {
      'xlsx' => new Xlsx(),
      'xls'  => new Xls(),
      default => IOFactory::createReaderForFile($path), // fallback
    };
  }

  private function detectMaxRowByChunks(IReader $reader, string $path, int $headerRow, array $colMap, ?string $sheetName): int
  {
    $chunk = 2000;
    $lastNonEmpty = $headerRow;
    $emptyBlocks = 0;

    for ($start = $headerRow + 1; $start <= 2000000; $start += $chunk) {
      $end = $start + $chunk - 1;

      $reader->setReadFilter(new ChunkReadFilter($start, $end, $sheetName));
      $spreadsheet = $reader->load($path);
      $sheet = $spreadsheet->getActiveSheet();

      $found = false;
      for ($r = $start; $r <= $end; $r++) {
        if (!$this->isRowEmpty($sheet, $r, $colMap)) {
          $lastNonEmpty = $r;
          $found = true;
        }
      }

      $spreadsheet->disconnectWorksheets();
      unset($spreadsheet);
      gc_collect_cycles();

      if ($found) {
        $emptyBlocks = 0;
        continue;
      }

      $emptyBlocks++;
      if ($emptyBlocks >= 2) break;
    }

    return $lastNonEmpty;
  }

  /**
   * @return array{0:?int,1:array<string,int>}
   */
  private function detectHeaderRowAndMap(Worksheet $sheet): array
  {
    $expected = [
      'journee'       => ['journée', 'journee', 'date', 'journÃ©e'],
      'horaire'       => ['horaire', 'heure'],
      'vehicule'      => ['véhicule', 'vehicule', 'vÃ©hicule'],
      'code_veh'      => ['code véh.', 'code veh.', 'code veh', 'code vÃ©h.'],
      'code_ag'       => ['code ag.', 'code ag', 'code agent'],
      'agent'         => ['agent'],
      'operation'     => ['opération', 'operation', 'opÃ©ration'],
      'cuve'          => ['cuve'],
      'quantite'      => ['quantité', 'quantite', 'quantitÃ©'],
      'prix_unitaire' => ['prix unitaire', 'prix unitaire eur', 'prix'],
      'compteur'      => ['compteur'],
    ];

    $highestColIndex = max(
      Coordinate::columnIndexFromString($sheet->getHighestColumn()),
      Coordinate::columnIndexFromString($sheet->getHighestDataColumn())
    );

    $maxCol = max(1, min(80, $highestColIndex));
    $maxRow = min(250, max($sheet->getHighestRow(), $sheet->getHighestDataRow()));

    $bestRow = null;
    $bestScore = 0;
    $bestMap = [];

    $forcedHeaderRow = $this->findRowContaining($sheet, $maxRow, $maxCol, 'horaire');

    $startRow = $forcedHeaderRow ?? 1;

    for ($r = $startRow; $r <= $maxRow; $r++) {
      $rowValues = [];
      for ($c = 1; $c <= $maxCol; $c++) {
        $v = $sheet->getCell([$c, $r])->getValue();
        if ($v === null || $v === '') continue;
        $rowValues[$c] = $this->normHeader((string)$v);
      }
      if (!$rowValues) continue;

      $map = [];
      $score = 0;

      foreach ($expected as $key => $labels) {
        foreach ($rowValues as $c => $h) {
          foreach ($labels as $label) {
            $labelNorm = $this->normHeader($label);
            if ($h === $labelNorm || str_contains($h, $labelNorm) || str_contains($labelNorm, $h)) {
              $map[$key] = $c;
              $score++;
              break 2;
            }
          }
        }
      }

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestRow = $r;
        $bestMap = $map;
      }

      if ($forcedHeaderRow !== null && $r === $forcedHeaderRow && $score >= 6) {
        break;
      }
    }

    // on veut au moins la majorité des colonnes
    if ($bestScore < 6) {
      return [null, []];
    }

    return [$bestRow, $bestMap];
  }

  private function findRowContaining(Worksheet $sheet, int $maxRow, int $maxCol, string $needle): ?int
  {
    $needle = $this->normHeader($needle);
    for ($r = 1; $r <= $maxRow; $r++) {
      for ($c = 1; $c <= $maxCol; $c++) {
        $v = $sheet->getCell([$c, $r])->getValue();
        if ($v === null || $v === '') continue;
        $h = $this->normHeader((string)$v);
        if ($h === $needle || str_contains($h, $needle)) return $r;
      }
    }
    return null;
  }

  private function normHeader(string $s): string
  {
    $s = trim($s);

    // ✅ Fix “mojibake” courant (JournÃ©e, VÃ©hicule, OpÃ©ration...)
    if (str_contains($s, 'Ã')) {
      $s = utf8_encode(utf8_decode($s));
    }

    $s = mb_strtolower($s);
    $s = str_replace(['’', "'"], ' ', $s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('~[^a-z0-9%/.\- ]+~', ' ', $s) ?: $s;
    $s = preg_replace('~\s+~', ' ', $s) ?: $s;
    return trim($s);
  }

  private function isRowEmpty(Worksheet $sheet, int $row, array $colMap): bool
  {
    foreach (['journee', 'horaire', 'vehicule', 'quantite'] as $k) {
      if (!isset($colMap[$k])) continue;
      $v = $sheet->getCell([$colMap[$k], $row])->getValue();
      if ($v !== null && $v !== '') return false;
    }
    return true;
  }

  /**
   * @return array<string,mixed>
   */
  private function readRow(Worksheet $sheet, int $row, array $colMap): array
  {
    $out = [];
    foreach ($colMap as $key => $col) {
      $out[$key] = $sheet->getCell([$col, $row])->getValue();
    }
    return $out;
  }

  private function buildImportKey(int $entiteId, array $row): string
  {
    $parts = [
      (string)$entiteId,
      (string)($row['journee'] ?? ''),
      (string)($row['horaire'] ?? ''),
      (string)($row['vehicule'] ?? ''),
      (string)($row['code_veh'] ?? ''),
      (string)($row['code_ag'] ?? ''),
      (string)($row['operation'] ?? ''),
      (string)($row['cuve'] ?? ''),
      (string)($row['quantite'] ?? ''),
      (string)($row['prix_unitaire'] ?? ''),
    ];
    return sha1(implode('|', $parts));
  }

  private function toStringClean(mixed $v): ?string
  {
    if ($v === null || $v === '') return null;
    $s = trim((string)$v);
    if ($s === '') return null;

    // fix mojibake aussi sur valeurs
    if (str_contains($s, 'Ã')) {
      $s = utf8_encode(utf8_decode($s));
    }
    return $s;
  }

  private function toInt(mixed $v): ?int
  {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (int) round((float)$v);
    $s = preg_replace('~[^0-9\-]~', '', (string)$v);
    return $s === '' ? null : (int)$s;
  }

  private function toDecimalString(mixed $v, int $scale): ?string
  {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return number_format((float)$v, $scale, '.', '');
    $s = trim((string)$v);
    $s = str_replace([' ', "\u{00A0}"], '', $s);
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return number_format((float)$s, $scale, '.', '');
  }

  private function toDateImmutable(mixed $v): ?\DateTimeImmutable
  {
    if ($v === null || $v === '') return null;

    // ✅ ton fichier : yyyymmdd (20260220)
    if (is_numeric($v)) {
      $n = (string)(int)$v;
      if (strlen($n) === 8) {
        $d = \DateTimeImmutable::createFromFormat('Ymd', $n);
        return $d?->setTime(0, 0, 0);
      }

      // fallback excel serial
      $dt = XlsDate::excelToDateTimeObject((float)$v);
      return \DateTimeImmutable::createFromMutable($dt)->setTime(0, 0, 0);
    }

    $s = trim((string)$v);
    $d = \DateTimeImmutable::createFromFormat('d/m/Y', $s)
      ?: \DateTimeImmutable::createFromFormat('Y-m-d', $s)
      ?: \DateTimeImmutable::createFromFormat('Ymd', $s);

    return $d?->setTime(0, 0, 0);
  }

  private function toTimeImmutable(mixed $v): ?\DateTimeImmutable
  {
    if ($v === null || $v === '') return null;

    if ($v instanceof \DateTimeInterface) {
      return (new \DateTimeImmutable($v->format('Y-m-d H:i:s'))); // garde l'heure
    }

    if (is_numeric($v)) {
      $dt = XlsDate::excelToDateTimeObject((float)$v);
      return \DateTimeImmutable::createFromMutable($dt);
    }

    $s = trim((string)$v);
    return \DateTimeImmutable::createFromFormat('H:i:s', $s)
      ?: \DateTimeImmutable::createFromFormat('H:i', $s);
  }

  private function setIf(object $obj, string $prop, mixed $val): void
  {
    if ($val === null || $val === '') return;
    $m = 'set' . ucfirst($prop);
    if (method_exists($obj, $m)) {
      $obj->{$m}($val);
    }
  }
}
