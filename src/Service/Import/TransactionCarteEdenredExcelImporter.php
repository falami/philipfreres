<?php
// src/Service/Import/TransactionCarteEdenredExcelImporter.php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Entite;
use App\Entity\TransactionCarteEdenred;
use App\Enum\ExternalProvider;
use App\Service\Carburant\FuelKey;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class TransactionCarteEdenredExcelImporter
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

    $path     = $file->getPathname();
    $filename = $file->getClientOriginalName() ?: 'import';

    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    $repo = $this->em->getRepository(TransactionCarteEdenred::class);
    $seenKeys = [];

    // =========================================================
    // ✅ CSV STREAMING
    // =========================================================
    if ($this->isCsvFile($file)) {
      try {
        $dialect = $this->detectCsvDialect($path);
        $csv     = $this->openCsv($path, $dialect['delimiter']);

        [$headerRow, $colMap] = $this->detectHeaderRowAndMapFromCsv($csv);
        if ($headerRow === null) {
          return [
            'imported' => 0,
            'skipped' => 0,
            'errors' => ["Entêtes introuvables : le fichier ne ressemble pas au format EDENRED attendu."],
          ];
        }

        // se replacer après les entêtes
        $csv->rewind();
        for ($i = 1; $i <= $headerRow; $i++) {
          $csv->fgetcsv();
        }

        $batchSize = 250;
        $batch = 0;

        $this->em->beginTransaction();
        $entiteRef = $this->em->getReference(Entite::class, $entite->getId());

        $rowNum = $headerRow;
        while (!$csv->eof()) {
          $rowNum++;
          $raw = $csv->fgetcsv();

          if ($raw === false || $raw === null || $raw === [null]) {
            continue;
          }

          // normaliser encodage cellule par cellule (souvent Windows-1252)
          $raw = array_map(fn($v) => $this->toUtf8($v), $raw);

          if ($this->isCsvRowEmpty($raw, $colMap)) {
            continue;
          }

          try {
            $row = $this->readRowFromCsv($raw, $colMap);
            $importKey = $this->buildImportKey($entite->getId(), $row);

            if (isset($seenKeys[$importKey])) {
              $skipped++;
              continue;
            }
            $seenKeys[$importKey] = true;

            $exists = $repo->findOneBy(['entite' => $entiteRef, 'importKey' => $importKey]);
            if ($exists) {
              $skipped++;
              continue;
            }

            $t = (new TransactionCarteEdenred())
              ->setEntite($entiteRef)
              ->setSourceFilename($filename)
              ->setSourceRow($rowNum)
              ->setImportKey($importKey);

            $this->mapTransaction($t, $row);

            // ✅ comme ALX : capture erreurs resolver avec origine + previous
            try {
              $this->resolver->resolveEdenred($entite, $t, false);
            } catch (\Throwable $e) {
              $origin = $this->firstNonVendorFrame($e);
              $errors[] = "Ligne {$rowNum} (link): " . $e->getMessage() . ($origin ? " | {$origin}" : "");
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
              $this->em->clear(TransactionCarteEdenred::class);
              $entiteRef = $this->em->getReference(Entite::class, $entite->getId());
              $batch = 0;
            }
          } catch (\Throwable $e) {
            $errors[] = "Ligne {$rowNum}: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine();
            $skipped++;
          }
        }

        $this->em->flush();
        $this->em->clear(TransactionCarteEdenred::class);
        $this->em->commit();
      } catch (\Throwable $e) {
        $this->em->rollback();
        $errors[] = "Import interrompu: " . $e->getMessage();
      }

      return compact('imported', 'skipped', 'errors');
    }

    // =========================================================
    // ✅ XLS/XLSX CHUNK (aligné ALX)
    // =========================================================

    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $reader->setReadEmptyCells(false);

    $sheetName = $this->pickBestSheetName($path);
    if ($sheetName !== null) {
      $reader->setLoadSheetsOnly([$sheetName]);
    }

    // scan entêtes sur 250 premières lignes
    $reader->setReadFilter(new ChunkReadFilter(1, 250, $sheetName));
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();

    [$headerRow, $colMap] = $this->detectHeaderRowAndMapFromSheet($sheet);

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    gc_collect_cycles();

    if ($headerRow === null) {
      return [
        'imported' => 0,
        'skipped' => 0,
        'errors' => ["Entêtes introuvables : le fichier ne ressemble pas au format EDENRED attendu."],
      ];
    }

    $maxRow = $this->detectMaxRowByChunksXlsx($reader, $path, $headerRow, $colMap, $sheetName);
    if ($maxRow <= $headerRow) {
      return [
        'imported' => 0,
        'skipped' => 0,
        'errors' => ["Aucune ligne de données détectée après les entêtes."],
      ];
    }

    $chunkSize = 1000;
    $batchSize = 250;

    try {
      $this->em->beginTransaction();

      for ($start = $headerRow + 1; $start <= $maxRow; $start += $chunkSize) {
        $end = min($start + $chunkSize - 1, $maxRow);

        $reader->setReadFilter(new ChunkReadFilter($start, $end, $sheetName));
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $entiteRef = $this->em->getReference(Entite::class, $entite->getId());
        $batch = 0;

        // 1) collecter les rows + keys du chunk
        $rowsBuffer = [];
        $keysBuffer = [];

        for ($r = $start; $r <= $end; $r++) {
          if ($this->isSheetRowEmpty($sheet, $r, $colMap)) {
            continue;
          }

          try {
            $row = $this->readRowFromSheet($sheet, $r, $colMap);
            $importKey = $this->buildImportKey($entite->getId(), $row);

            // doublons internes fichier
            if (isset($seenKeys[$importKey])) {
              $skipped++;
              continue;
            }
            $seenKeys[$importKey] = true;

            $rowsBuffer[] = [$r, $row, $importKey];
            $keysBuffer[] = $importKey;
          } catch (\Throwable $e) {
            $errors[] = "Ligne {$r}: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine();
            $skipped++;
          }
        }

        // 2) demander en 1 requête celles déjà en base
        $existing = $this->fetchExistingKeys($entiteRef, $keysBuffer);

        // 3) persister uniquement les nouvelles
        foreach ($rowsBuffer as [$r, $row, $importKey]) {
          if (isset($existing[$importKey])) {
            $skipped++;
            continue;
          }

          try {
            $t = (new TransactionCarteEdenred())
              ->setEntite($entiteRef)
              ->setSourceFilename($filename)
              ->setSourceRow($r)
              ->setImportKey($importKey);

            $this->mapTransaction($t, $row);

            try {
              $this->resolver->resolveEdenred($entite, $t, false);
            } catch (\Throwable $e) {
              $origin = $this->firstNonVendorFrame($e);
              $errors[] = "Ligne {$r} (link): " . $e->getMessage() . ($origin ? " | {$origin}" : "");
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
              $this->em->clear(TransactionCarteEdenred::class);
              $entiteRef = $this->em->getReference(Entite::class, $entite->getId());
              $batch = 0;
            }
          } catch (\Throwable $e) {
            $errors[] = "Ligne {$r}: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine();
            $skipped++;
          }
        }

        $this->em->flush();
        $this->em->clear(TransactionCarteEdenred::class);

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


  /** @return array<string,true> */
  private function fetchExistingKeys(Entite $entiteRef, array $keys): array
  {
    $keys = array_values(array_unique(array_filter($keys)));
    if (!$keys) return [];

    $qb = $this->em->createQueryBuilder();
    $rows = $qb
      ->select('t.importKey')
      ->from(TransactionCarteEdenred::class, 't')
      ->andWhere('t.entite = :entite')
      ->andWhere('t.importKey IN (:keys)')
      ->setParameter('entite', $entiteRef)
      ->setParameter('keys', $keys)
      ->getQuery()
      ->getScalarResult();

    $out = [];
    foreach ($rows as $r) {
      $k = (string)($r['importKey'] ?? '');
      if ($k !== '') $out[$k] = true;
    }
    return $out;
  }

  // =========================================================
  // ✅ Diagnostics (comme ALX)
  // =========================================================

  private function firstNonVendorFrame(\Throwable $e): ?string
  {
    foreach ($e->getTrace() as $t) {
      $file = $t['file'] ?? null;
      $line = $t['line'] ?? null;
      if (!$file || !$line) continue;

      if (str_contains($file, '/vendor/')) continue;
      if (str_contains($file, '/var/cache/')) continue;

      return $file . ':' . $line;
    }

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

      foreach ($names as $n) {
        if (stripos((string)$n, 'export') !== false) {
          return (string)$n;
        }
      }
      return (string)$names[0];
    } catch (\Throwable) {
      return null;
    }
  }

  private function makeSheetListingReader(string $path): BaseReader
  {
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
      'xlsx' => new Xlsx(),
      'xls'  => new Xls(),
      default => IOFactory::createReaderForFile($path),
    };
  }

  // =========================================================
  // ✅ CSV helpers
  // =========================================================

  private function isCsvFile(UploadedFile $file): bool
  {
    $orig = strtolower((string) $file->getClientOriginalName());
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $mime = strtolower((string) $file->getClientMimeType());

    return $ext === 'csv'
      || str_contains($mime, 'csv')
      || $mime === 'text/plain'
      || $mime === 'application/vnd.ms-excel';
  }

  /** @return array{delimiter:string} */
  private function detectCsvDialect(string $path): array
  {
    $sample = @file_get_contents($path, false, null, 0, 8192) ?: '';
    if (str_starts_with($sample, "\xEF\xBB\xBF")) {
      $sample = substr($sample, 3);
    }
    $firstLine = strtok($sample, "\r\n") ?: $sample;

    $counts = [
      ';'  => substr_count($firstLine, ';'),
      ','  => substr_count($firstLine, ','),
      "\t" => substr_count($firstLine, "\t"),
    ];
    arsort($counts);
    $delimiter = array_key_first($counts) ?: ';';

    return ['delimiter' => $delimiter];
  }

  private function openCsv(string $path, string $delimiter): \SplFileObject
  {
    $csv = new \SplFileObject($path, 'r');
    $csv->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
    $csv->setCsvControl($delimiter, '"', '\\');
    return $csv;
  }

  /** @return array{0:?int,1:array<string,int>} */
  private function detectHeaderRowAndMapFromCsv(\SplFileObject $csv): array
  {
    $expected = $this->expectedHeaders();

    $bestRow = null;
    $bestScore = 0;
    $bestMap = [];

    $csv->rewind();

    for ($r = 1; $r <= 250 && !$csv->eof(); $r++) {
      $line = $csv->fgetcsv();
      if (!is_array($line) || $line === [null]) continue;

      $line = array_map(fn($v) => $this->toUtf8($v), $line);

      $rowValues = [];
      foreach ($line as $i => $cell) {
        $cell = trim((string)$cell);
        if ($cell === '') continue;
        $rowValues[$i] = $this->normHeader($cell);
      }
      if (!$rowValues) continue;

      [$score, $map] = $this->scoreAndMap($rowValues, $expected);

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestRow = $r;
        $bestMap = $map;
      }

      if ($bestScore >= 14 && isset($map['date_transaction'], $map['numero_transaction'], $map['montant_ttc'])) {
        break;
      }
    }

    if ($bestRow === null || $bestScore < 10) return [null, []];

    foreach (['date_transaction', 'numero_transaction', 'montant_ttc'] as $must) {
      if (!isset($bestMap[$must])) return [null, []];
    }

    return [$bestRow, $bestMap];
  }

  private function isCsvRowEmpty(array $raw, array $colMap): bool
  {
    foreach (['date_transaction', 'produit', 'montant_ttc', 'carte_numero', 'numero_transaction'] as $k) {
      if (!isset($colMap[$k])) continue;
      $idx = $colMap[$k];
      $v = $raw[$idx] ?? null;
      if ($v !== null && trim((string)$v) !== '') return false;
    }
    return true;
  }

  /** @return array<string,mixed> */
  private function readRowFromCsv(array $raw, array $colMap): array
  {
    $out = [];
    foreach ($colMap as $key => $idx) {
      $out[$key] = $raw[$idx] ?? null;
    }
    return $out;
  }

  private function toUtf8(mixed $v): string
  {
    if ($v === null) return '';
    $s = (string)$v;
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;

    if (!mb_check_encoding($s, 'UTF-8')) {
      $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
    }
    return $s;
  }

  // =========================================================
  // ✅ XLSX helpers
  // =========================================================

  /** @return array{0:?int,1:array<string,int>} */
  private function detectHeaderRowAndMapFromSheet(Worksheet $sheet): array
  {
    $expected = $this->expectedHeaders();

    $highestColIndex = max(
      Coordinate::columnIndexFromString($sheet->getHighestColumn()),
      Coordinate::columnIndexFromString($sheet->getHighestDataColumn())
    );

    $maxCol = max(1, min(250, $highestColIndex));
    $maxRow = min(250, max($sheet->getHighestRow(), $sheet->getHighestDataRow()));

    $bestRow = null;
    $bestScore = 0;
    $bestMap = [];

    for ($r = 1; $r <= $maxRow; $r++) {
      $rowValues = [];
      for ($c = 1; $c <= $maxCol; $c++) {
        $v = $sheet->getCell([$c, $r])->getValue();
        if ($v === null || $v === '') continue;
        $rowValues[$c] = $this->normHeader((string)$v);
      }
      if (!$rowValues) continue;

      [$score, $map] = $this->scoreAndMap($rowValues, $expected);

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestRow = $r;
        $bestMap = $map;
      }

      if ($bestScore >= 14 && isset($map['date_transaction'], $map['numero_transaction'], $map['montant_ttc'])) {
        break;
      }
    }

    if ($bestRow === null || $bestScore < 10) return [null, []];

    foreach (['date_transaction', 'numero_transaction', 'montant_ttc'] as $must) {
      if (!isset($bestMap[$must])) return [null, []];
    }

    return [$bestRow, $bestMap];
  }

  private function detectMaxRowByChunksXlsx(IReader $reader, string $path, int $headerRow, array $colMap, ?string $sheetName): int
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
        if (!$this->isSheetRowEmpty($sheet, $r, $colMap)) {
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

  private function isSheetRowEmpty(Worksheet $sheet, int $row, array $colMap): bool
  {
    foreach (['date_transaction', 'produit', 'montant_ttc', 'carte_numero', 'numero_transaction'] as $k) {
      if (!isset($colMap[$k])) continue;
      $v = $sheet->getCell([$colMap[$k], $row])->getValue();
      if ($v !== null && $v !== '') return false;
    }
    return true;
  }

  /** @return array<string,mixed> */
  private function readRowFromSheet(Worksheet $sheet, int $row, array $colMap): array
  {
    $out = [];
    foreach ($colMap as $key => $col) {
      $out[$key] = $sheet->getCell([$col, $row])->getValue();
    }
    return $out;
  }

  // =========================================================
  // ✅ Header matching
  // =========================================================

  /** @return array{0:int,1:array<string,int>} */
  private function scoreAndMap(array $rowValues, array $expected): array
  {
    $map = [];
    $score = 0;

    foreach ($expected as $key => $labels) {
      $bestCol = null;

      // 1) ✅ PASS EXACT MATCH (prioritaire)
      foreach ($rowValues as $col => $h) {
        foreach ($labels as $label) {
          $labelNorm = $this->normHeader($label);
          if ($h === $labelNorm) {
            $bestCol = $col;
            break 2;
          }
        }
      }

      // 2) ✅ PASS "CONTAINS" seulement si aucun exact
      if ($bestCol === null) {
        foreach ($rowValues as $col => $h) {
          foreach ($labels as $label) {
            $labelNorm = $this->normHeader($label);
            if ($labelNorm !== '' && str_contains($h, $labelNorm)) {
              $bestCol = $col;
              break 2;
            }
          }
        }
      }

      if ($bestCol !== null) {
        $map[$key] = $bestCol;
        $score++;
      }
    }

    return [$score, $map];
  }

  private function expectedHeaders(): array
  {
    return [
      'enseigne' => ['enseigne'],

      'site_code_site' => ['site : code site', 'site code site'],
      'site_numero_terminal' => ['site : numero de terminal', 'site : numéro de terminal'],
      'site_libelle' => ['site : libelle', 'site : libellé', 'site : libellé du point de vente', 'site : libelle du point de vente'],
      'site_libelle_court' => ['site : libelle court', 'site : libellé court'],
      'site_type' => ['site : type du site'],

      'client_reference' => ['client : reference client', 'client : référence client'],
      'client_nom' => ['client : nom du client'],

      'carte_type' => ['carte : type de carte'],
      'carte_numero' => ['carte : numero de carte', 'carte : numéro de carte'],
      'carte_validite' => ['carte : date de validite', 'carte : date de validité'],

      'numero_tlc' => ['numero de tlc', 'numéro de tlc'],
      'date_telecollecte' => ['date de telecollecte', 'date de télécollecte'],
      'type_transaction' => ['type de transaction'],
      'numero_transaction' => ['numero de transaction', 'numéro de transaction'],
      'date_transaction' => ['date de transaction'],
      'reference_transaction' => ['reference transaction', 'référence transaction'],

      'code_devise' => ['code devise'],
      'code_produit' => ['code produit'],
      'produit' => ['produit'],
      'prix_unitaire' => ['prix unitaire'],
      'quantite' => ['quantite', 'quantité'],
      'montant_ttc' => ['montant ttc'],
      'montant_ht' => ['montant ht'],

      'code_vehicule' => ['code vehicule', 'code véhicule'],
      'code_chauffeur' => ['code chauffeur'],
      'kilometrage' => ['kilometrage', 'kilométrage'],
      'immatriculation' => ['immatriculation'],

      'code_reponse' => ['code reponse', 'code réponse'],
      'numero_opposition' => ['numero opposition', "numéro opposition", "numéro d opposition", "numéro d'opposition"],
      'numero_autorisation' => ["numero autorisation", "numéro autorisation", "numéro d autorisation", "numéro d'autorisation"],
      'motif_autorisation' => ["motif autorisation", "motif d autorisation", "motif d'autorisation"],

      'mode_transaction' => ['mode de transaction'],
      'mode_vente' => ['mode de vente'],
      'mode_validation' => ['mode de validation'],
      'facturation_client' => ['facturation client'],
      'facturation_site' => ['facturation site'],
      'solde_apres' => ['solde apres', 'solde après'],
      'numero_facture' => ['numero de facture', 'numéro de facture'],
      'avoir_gerant' => ['avoir gerant', 'avoir gérant'],
    ];
  }

  private function normHeader(string $s): string
  {
    $s = trim($s);
    $s = $this->toUtf8($s);

    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(["\u{00A0}", "\xC2\xA0"], ' ', $s);
    $s = str_replace(['’', "'"], ' ', $s);

    if (class_exists(\Transliterator::class)) {
      $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
      if ($tr) $s = $tr->transliterate($s);
    } else {
      $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }

    $s = preg_replace('~[^a-z0-9%/ :_-]+~', ' ', $s) ?: $s;
    $s = preg_replace('~\s+~', ' ', $s) ?: $s;

    return trim($s);
  }

  // =========================================================
  // ✅ Mapping transaction
  // =========================================================

  /** @param array<string,mixed> $row */
  private function mapTransaction(TransactionCarteEdenred $t, array $row): void
  {
    $this->setIf($t, 'enseigne', $row['enseigne'] ?? null);

    $this->setIf($t, 'siteCodeSite', $row['site_code_site'] ?? null);
    $this->setIf($t, 'siteNumeroTerminal', $row['site_numero_terminal'] ?? null);
    $this->setIf($t, 'siteLibelle', $row['site_libelle'] ?? null);
    $this->setIf($t, 'siteLibelleCourt', $row['site_libelle_court'] ?? null);
    $this->setIf($t, 'siteType', $row['site_type'] ?? null);

    $this->setIf($t, 'clientReference', $row['client_reference'] ?? null);
    $this->setIf($t, 'clientNom', $row['client_nom'] ?? null);

    $this->setIf($t, 'carteType', $row['carte_type'] ?? null);
    $this->setIf($t, 'carteNumero', $this->cleanQuoted($row['carte_numero'] ?? null));
    $this->setIf($t, 'carteValidite', $this->cleanQuoted($row['carte_validite'] ?? null));

    $this->setIf($t, 'numeroTlc', $this->cleanQuoted($row['numero_tlc'] ?? null));
    if (($d = $this->toDateTimeImmutable($row['date_telecollecte'] ?? null)) !== null) {
      $t->setDateTelecollecte($d);
    }

    $this->setIf($t, 'typeTransaction', $row['type_transaction'] ?? null);
    $this->setIf($t, 'numeroTransaction', $this->cleanQuoted($row['numero_transaction'] ?? null));
    if (($d = $this->toDateTimeImmutable($row['date_transaction'] ?? null)) !== null) {
      $t->setDateTransaction($d->setTime(0, 0, 0));
    }

    $this->setIf($t, 'referenceTransaction', $row['reference_transaction'] ?? null);
    $this->setIf($t, 'codeDevise', $row['code_devise'] ?? null);
    $this->setIf($t, 'codeProduit', $row['code_produit'] ?? null);
    $this->setIf($t, 'produit', $row['produit'] ?? null);

    $t->setPrixUnitaire($this->toDecimalString($row['prix_unitaire'] ?? null, 4));
    $t->setQuantite($this->toDecimalString($row['quantite'] ?? null, 3));
    $t->setMontantTtc($this->toDecimalString($row['montant_ttc'] ?? null, 2));
    $t->setMontantHt($this->toDecimalString($row['montant_ht'] ?? null, 2));

    $this->setIf($t, 'codeVehicule', $this->cleanQuoted($row['code_vehicule'] ?? null));
    $this->setIf($t, 'codeChauffeur', $this->cleanQuoted($row['code_chauffeur'] ?? null));
    $this->setIf($t, 'kilometrage', $this->cleanQuoted($row['kilometrage'] ?? null));
    $this->setIf($t, 'immatriculation', $this->cleanQuoted($row['immatriculation'] ?? null));

    $this->setIf($t, 'codeReponse', $row['code_reponse'] ?? null);
    $this->setIf($t, 'numeroOpposition', $this->cleanQuoted($row['numero_opposition'] ?? null));
    $this->setIf($t, 'numeroAutorisation', $this->cleanQuoted($row['numero_autorisation'] ?? null));
    $this->setIf($t, 'motifAutorisation', $row['motif_autorisation'] ?? null);

    $this->setIf($t, 'modeTransaction', $row['mode_transaction'] ?? null);
    $this->setIf($t, 'modeVente', $row['mode_vente'] ?? null);
    $this->setIf($t, 'modeValidation', $row['mode_validation'] ?? null);

    $this->setIf($t, 'facturationClient', $row['facturation_client'] ?? null);
    $this->setIf($t, 'facturationSite', $row['facturation_site'] ?? null);
    $t->setSoldeApres($this->toDecimalString($row['solde_apres'] ?? null, 2));

    $this->setIf($t, 'numeroFacture', $this->cleanQuoted($row['numero_facture'] ?? null));
    $this->setIf($t, 'avoirGerant', $row['avoir_gerant'] ?? null);

    $t->setProvider(ExternalProvider::EDENRED);
  }

  private function buildImportKey(int $entiteId, array $row): string
  {
    $numTxn = FuelKey::norm($this->cleanQuoted($row['numero_transaction'] ?? null) ?? '') ?? '';
    $refTxn = FuelKey::norm($this->cleanQuoted($row['reference_transaction'] ?? null) ?? '') ?? '';
    $tlc    = FuelKey::norm($this->cleanQuoted($row['numero_tlc'] ?? null) ?? '') ?? '';
    $carte  = FuelKey::norm($this->cleanQuoted($row['carte_numero'] ?? null) ?? '') ?? '';
    $site = FuelKey::norm($this->cleanQuoted($row['site_code_site'] ?? null) ?? '') ?? '';
    $prod = FuelKey::norm($this->cleanQuoted($row['produit'] ?? null) ?? '') ?? '';

    $dateTxn = $this->toDateTimeImmutable($row['date_transaction'] ?? null);
    $dateTel = $this->toDateTimeImmutable($row['date_telecollecte'] ?? null);
    $date = ($dateTxn ?? $dateTel)?->format('Y-m-d') ?? '';

    $ttc = $this->toDecimalString($row['montant_ttc'] ?? null, 2) ?? '';
    $qty = $this->toDecimalString($row['quantite'] ?? null, 3) ?? '';

    if ($numTxn !== '' && strlen($numTxn) >= 4) {
      return sha1(implode('|', [(string)$entiteId, 'N', $numTxn, $date, $carte, $ttc]));
    }

    if ($refTxn !== '') {
      return sha1(implode('|', [(string)$entiteId, 'R', $refTxn, $date, $carte, $ttc]));
    }

    if ($tlc !== '') {
      return sha1(implode('|', [(string)$entiteId, 'T', $tlc, $date, $carte, $ttc]));
    }

    return sha1(implode('|', [(string)$entiteId, 'F', $date, $carte, $ttc, $qty, $prod, $site]));
  }

  // =========================================================
  // Parsers
  // =========================================================

  private function cleanQuoted(mixed $v): ?string
  {
    if ($v === null || $v === '') return null;
    $s = trim((string)$v);
    $s = preg_replace('~^\'+~', '', $s) ?: $s;
    return $s;
  }

  private function toDecimalString(mixed $v, int $scale): ?string
  {
    if ($v === null || $v === '') return null;

    // 1) normaliser en string
    $s = is_string($v) ? $v : (string)$v;
    $s = trim($s);
    $s = str_replace(["\u{00A0}", ' '], '', $s);
    $s = preg_replace('~^\'+~', '', $s) ?: $s;
    $s = str_replace(',', '.', $s);

    if ($s === '' || !preg_match('~^-?\d+(\.\d+)?$~', $s)) return null;

    // 2) forcer scale sans float (BCMath si dispo, sinon fallback propre)
    if (extension_loaded('bcmath')) {
      return bcadd($s, '0', $scale);
    }

    // fallback: arrondi sans surprises majeures
    return number_format((float)$s, $scale, '.', '');
  }

  private function toDateTimeImmutable(mixed $v): ?\DateTimeImmutable
  {
    if ($v === null || $v === '') return null;

    if (is_numeric($v)) {
      $dt = XlsDate::excelToDateTimeObject((float)$v);
      return \DateTimeImmutable::createFromMutable($dt);
    }

    $s = trim((string)$v);
    $s = preg_replace('~^\'+~', '', $s) ?: $s;

    $try = [
      'd/m/Y H:i:s',
      'd/m/Y H:i',
      'd/m/Y',
      'd-m-Y H:i:s',
      'd-m-Y H:i',
      'd-m-Y',
      'Y-m-d H:i:s',
      'Y-m-d H:i',
      'Y-m-d',
    ];

    foreach ($try as $fmt) {
      $dt = \DateTimeImmutable::createFromFormat($fmt, $s);
      if ($dt instanceof \DateTimeImmutable) return $dt;
    }

    $ts = strtotime($s);
    return $ts ? (new \DateTimeImmutable())->setTimestamp($ts) : null;
  }

  private function setIf(object $obj, string $prop, mixed $val): void
  {
    if ($val === null) return;
    if (is_string($val)) {
      $val = trim($val);
      if ($val === '') return;
    }
    $m = 'set' . ucfirst($prop);
    if (method_exists($obj, $m)) {
      $obj->{$m}($val);
    }
  }
}
