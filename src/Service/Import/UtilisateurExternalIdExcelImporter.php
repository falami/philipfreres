<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurExternalId;
use App\Enum\ExternalProvider;
use App\Repository\UtilisateurExternalIdRepository;
use App\Repository\UtilisateurRepository;
use App\Service\Carburant\FuelKey;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UtilisateurExternalIdExcelImporter
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly UtilisateurRepository $userRepo,
    private readonly UtilisateurExternalIdRepository $extRepo,
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
        'errors' => [0 => "Impossible de détecter la ligne d’entêtes (provider/value + utilisateur_id/email/nom+prenom)."],
        'warnings' => [],
      ];
    }

    // 2) Mapping colonnes
    $spreadsheetHeader = $this->loadRange($path, 1, $headerRow, null);
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

    $hasUserKey = isset($colMap['utilisateur_id']) || isset($colMap['email']) || (isset($colMap['nom']) && isset($colMap['prenom']));
    if (!$hasUserKey) {
      return [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => 0,
        'headerRow' => $headerRow,
        'errors' => [0 => "Il faut une colonne pour identifier l’utilisateur : utilisateur_id OU email OU (nom+prenom)."],
        'warnings' => [],
      ];
    }

    // ============================
    // 🔥 INDEX UTILISATEURS (mémoire)
    // ============================
    $userById = [];
    $userByEmailKey = [];
    $userByNameKey = [];

    // à adapter si tu veux limiter aux utilisateurs liés à l'entité via UtilisateurEntite
    // ici on prend les utilisateurs dont $user->getEntite() === $entite (comme dans ton modèle actuel)
    foreach ($this->userRepo->findBy(['entite' => $entite]) as $u) {
      if ($u->getId()) $userById[(int) $u->getId()] = $u;

      $ek = $this->emailKey($u->getEmail());
      if ($ek) $userByEmailKey[$ek] = $u;

      $nk = $this->userNameKey($u->getNom(), $u->getPrenom());
      if ($nk) $userByNameKey[$nk] = $u;
    }

    // ============================
    // Compteurs
    // ============================
    $imported = 0;
    $updated  = 0;
    $skipped  = 0;
    $total    = 0;
    $errors   = [];
    $warnings = [];

    $batchFlush = 100;
    $processedSinceFlush = 0;

    // Doublons dans le fichier
    $seen = []; // userId|provider|valueLower

    // CSV ?
    $originalName = (string) ($file->getClientOriginalName() ?: '');
    $ext = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $isCsv = ($reader instanceof CsvReader) || ($ext === 'csv') || str_ends_with(mb_strtolower($path), '.csv');

    if (!$isCsv) {
      return [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => 0,
        'headerRow' => $headerRow,
        'errors' => [0 => "Format non supporté ici : merci d’envoyer un CSV."],
        'warnings' => [],
      ];
    }

    // ==========================================================
    // ✅ CSV : lecture full
    // ==========================================================
    $spreadsheet = $this->createReader($path)->load($path);
    $sheet = $spreadsheet->getSheet(0);

    $highestRow = (int) $sheet->getHighestRow();
    $highestCol = Coordinate::columnIndexFromString((string) $sheet->getHighestColumn());

    for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
      if ($this->isRowEmpty($sheet, $row, $highestCol)) continue;

      $total++;

      try {
        $data = $this->extractRowData($sheet, $row, $colMap);

        // 1) Resolve utilisateur
        $user = $this->resolveUser($data, $userById, $userByEmailKey, $userByNameKey);
        if (!$user) {
          $skipped++;
          $warnings[$row] = "Utilisateur introuvable (utilisateur_id/email/nom+prenom).";
          continue;
        }

        // 2) Provider
        $provider = $this->parseProvider($data['provider'] ?? null);
        if (!$provider) {
          $skipped++;
          $warnings[$row] = "Provider invalide (attendu : alx / total / edenred).";
          continue;
        }

        // 3) Value
        $value = $this->normalizeExternalValue($data['value'] ?? null);
        if (!$value) {
          $skipped++;
          $warnings[$row] = "Value vide ou invalide.";
          continue;
        }

        $active = $this->parseBool($data['active'] ?? null, true);
        $note   = $this->normalizeString($data['note'] ?? null);

        // Doublon dans fichier
        $dupKey = (int)$user->getId() . '|' . $provider->value . '|' . mb_strtolower($value);
        if (isset($seen[$dupKey])) {
          $skipped++;
          $warnings[$row] = "Doublon ignoré (déjà présent dans le fichier) : user={$user->getId()}, provider={$provider->value}, value={$value}.";
          continue;
        }
        $seen[$dupKey] = true;

        // Déjà en base ?
        $existing = $this->extRepo->findOneBy([
          'utilisateur' => $user,
          'provider' => $provider,
          'value' => $value,
        ]);

        if ($existing) {
          // option: tu peux réactiver / mettre note ici si tu veux
          $skipped++;
          $warnings[$row] = "Déjà existant en base : user={$user->getId()}, provider={$provider->value}, value={$value}.";
          continue;
        }

        // Création
        $extId = new UtilisateurExternalId($provider, $value);
        $extId->setUtilisateur($user);

        if ($note !== null) $extId->setNote($note);
        if (!$active && $extId->isActive()) $extId->disable($note);

        $this->em->persist($extId);
        $imported++;

        $processedSinceFlush++;
        if ($processedSinceFlush >= $batchFlush) {
          try {
            $this->em->flush();
            $processedSinceFlush = 0;
          } catch (UniqueConstraintViolationException) {
            $skipped++;
            $errors[$row] = "Doublon détecté au flush (contrainte unique) : user={$user->getId()}, provider={$provider->value}, value={$value}.";
            $this->em->clear();
            $processedSinceFlush = 0;
          }
        }
      } catch (\Throwable $e) {
        $skipped++;
        $errors[$row] = "Ligne $row : " . $e->getMessage();
      }
    }

    if ($processedSinceFlush > 0) {
      try {
        $this->em->flush();
      } catch (UniqueConstraintViolationException) {
        $errors[$highestRow] = "Doublon détecté au flush final (contrainte unique).";
        $this->em->clear();
      }
    }

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

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
  // Helpers (Reader / Header / Mapping)
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

  private function loadRange(string $path, int $start, int $end, ?string $sheetName): \PhpOffice\PhpSpreadsheet\Spreadsheet
  {
    $r = $this->createReader($path);
    $r->setReadFilter(new ChunkReadFilter($start, $end, $sheetName));
    return $r->load($path);
  }

  private function detectHeaderRow(IReader $reader, string $path, int $maxScanRows = 80): ?int
  {
    $scan = $this->loadRange($path, 1, $maxScanRows, null);
    $sheet = $scan->getSheet(0);
    $highestCol = Coordinate::columnIndexFromString((string) $sheet->getHighestColumn());

    $needles = ['provider', 'value', 'utilisateur_id', 'email', 'nom', 'prenom'];

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
      // user lookup
      'utilisateur_id' => ['utilisateur_id', 'user_id', 'id utilisateur', 'id user', 'id'],
      'email' => ['email', 'mail', 'e-mail'],
      'nom' => ['nom', 'lastname', 'name'],
      'prenom' => ['prenom', 'prénom', 'firstname', 'first_name'],

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
      'utilisateur_id' => $get('utilisateur_id'),
      'email' => $get('email'),
      'nom' => $get('nom'),
      'prenom' => $get('prenom'),
      'provider' => $get('provider'),
      'value' => $get('value'),
      'active' => $get('active'),
      'note' => $get('note'),
    ];
  }

  // -------------------------------------------------------------------
  // Business
  // -------------------------------------------------------------------

  private function resolveUser(array $data, array $userById, array $userByEmailKey, array $userByNameKey): ?Utilisateur
  {
    // 1) utilisateur_id
    $idRaw = trim((string) ($data['utilisateur_id'] ?? ''));
    if ($idRaw !== '' && ctype_digit($idRaw)) {
      $id = (int) $idRaw;
      if (isset($userById[$id])) return $userById[$id];
    }

    // 2) email
    $emailKey = $this->emailKey($data['email'] ?? null);
    if ($emailKey && isset($userByEmailKey[$emailKey])) {
      return $userByEmailKey[$emailKey];
    }

    // 3) nom+prenom
    $nk = $this->userNameKey($data['nom'] ?? null, $data['prenom'] ?? null);
    if ($nk && isset($userByNameKey[$nk])) {
      return $userByNameKey[$nk];
    }

    return null;
  }

  private function emailKey(?string $s): ?string
  {
    $s = mb_strtolower(trim((string) $s));
    if ($s === '') return null;
    $s = str_replace(["\u{00A0}", ' '], '', $s);
    return $s !== '' ? $s : null;
  }

  private function userNameKey(?string $nom, ?string $prenom): ?string
  {
    $n = mb_strtolower(trim((string) $nom));
    $p = mb_strtolower(trim((string) $prenom));
    $n = preg_replace('/\s+/', ' ', $n) ?: $n;
    $p = preg_replace('/\s+/', ' ', $p) ?: $p;

    if ($n === '' && $p === '') return null;
    return trim($n . '|' . $p);
  }

  private function parseProvider(?string $raw): ?ExternalProvider
  {
    $s = trim((string) $raw);
    if ($s === '') return null;

    $s = mb_strtolower($s);
    $s = str_replace(["\u{00A0}", ' '], '', $s);
    $s = str_replace(['-', '_', '.', '/', '\\'], '', $s);

    $map = [
      'alx' => 'alx',
      'cartealx' => 'alx',

      'total' => 'total',
      'totalenergies' => 'total',
      'totalenergie' => 'total',

      'edenred' => 'edenred',
      'ticketcar' => 'edenred',
      'edenredticketcar' => 'edenred',
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
}
