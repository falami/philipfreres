<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;

final class UtilisateurExcelImporter
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly UtilisateurRepository $userRepo,
    private readonly UtilisateurEntiteRepository $userEntiteRepo,
    private readonly UserPasswordHasherInterface $hasher,
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

    // 1) Détection de la ligne d’entêtes (scan 1..80)
    $headerRow = $this->detectHeaderRow($reader, $path, maxScanRows: 80);
    if ($headerRow === null) {
      return [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => 0,
        'headerRow' => null,
        'errors' => [0 => "Impossible de détecter la ligne d’entêtes (ex: email, nom, prénom)."],
        'warnings' => [],
      ];
    }

    // 2) Mapping colonnes -> champs (recharge jusqu’à headerRow)
    $sheetName = null;
    $spreadsheetHeader = $this->loadRange($reader, $path, 1, $headerRow, $sheetName);
    $sheetHeader = $spreadsheetHeader->getSheet(0);

    $highestColHeader = Coordinate::columnIndexFromString((string) $sheetHeader->getHighestColumn());
    $headers = $this->readRowValues($sheetHeader, $headerRow, $highestColHeader);
    $colMap = $this->buildColumnMap($headers);

    $spreadsheetHeader->disconnectWorksheets();
    unset($spreadsheetHeader);

    if (!isset($colMap['email'])) {
      return [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => 0,
        'headerRow' => $headerRow,
        'errors' => [0 => "Colonne EMAIL introuvable (ex: email, e-mail, mail, courriel)."],
        'warnings' => [],
      ];
    }

    // 3) Init compteurs
    $imported = 0;
    $updated  = 0;
    $skipped  = 0;
    $total    = 0;
    $errors   = [];
    $warnings = [];

    $batchFlush = 50;
    $processedSinceFlush = 0;

    // 4) Détection CSV (fiable)
    $originalName = (string) ($file->getClientOriginalName() ?: '');
    $ext = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $isCsv = ($reader instanceof CsvReader) || ($ext === 'csv') || str_ends_with(mb_strtolower($path), '.csv');

    // ==========================================================
    // ✅ CSV : lecture FULL (on évite ReadFilter+CSV)
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

          $email = $this->normalizeEmail($data['email'] ?? null);
          if (!$email) {
            $skipped++;
            $warnings[$row] = "Ligne $row ignorée : email vide/invalide.";
            continue;
          }

          $user = $this->userRepo->findOneBy(['email' => $email]);

          $isNew = false;
          if (!$user) {
            $isNew = true;

            $user = new Utilisateur();
            $user->setEmail($email);
            $user->setCreateur($createur);
            $user->setEntite($entite);
            $user->setIsVerified(false);

            $plain = bin2hex(random_bytes(10));
            $user->setPassword($this->hasher->hashPassword($user, $plain));

            $user->setRoles($this->parseRoles($data['roles'] ?? null));
          } else {
            // protection : déjà utilisé dans une autre entité (si tu utilises Utilisateur::entite)
            if ($user->getEntite() && $user->getEntite()->getId() !== $entite->getId()) {
              $skipped++;
              $warnings[$row] = "Ligne $row ignorée : email déjà utilisé dans une autre entité.";
              continue;
            }
          }

          $this->applyData($user, $data);
          $this->upsertUtilisateurEntite($user, $entite, $createur, $data);

          if ($user->getEntite() === null) $user->setEntite($entite);
          if ($user->getCreateur() === null) $user->setCreateur($createur);

          $this->em->persist($user);

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
    // ✅ XLSX/XLS : chunking (performant)
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

          $email = $this->normalizeEmail($data['email'] ?? null);
          if (!$email) {
            $skipped++;
            $warnings[$row] = "Ligne $row ignorée : email vide/invalide.";
            continue;
          }

          $user = $this->userRepo->findOneBy(['email' => $email]);

          $isNew = false;
          if (!$user) {
            $isNew = true;

            $user = new Utilisateur();
            $user->setEmail($email);
            $user->setCreateur($createur);
            $user->setEntite($entite);
            $user->setIsVerified(false);

            $plain = bin2hex(random_bytes(10));
            $user->setPassword($this->hasher->hashPassword($user, $plain));

            $user->setRoles($this->parseRoles($data['roles'] ?? null));
          } else {
            if ($user->getEntite() && $user->getEntite()->getId() !== $entite->getId()) {
              $skipped++;
              $warnings[$row] = "Ligne $row ignorée : email déjà utilisé dans une autre entité.";
              continue;
            }
          }

          $this->applyData($user, $data);

          if ($user->getEntite() === null) $user->setEntite($entite);
          if ($user->getCreateur() === null) $user->setCreateur($createur);

          $this->em->persist($user);

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
    if (!is_string($raw) || $raw === '') {
      return 'UTF-8';
    }

    // UTF-8 BOM
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
      return 'UTF-8';
    }

    // tentative detection
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
    $needles = ['email', 'e-mail', 'mail', 'courriel'];

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
      'email' => ['email', 'e mail', 'e-mail', 'mail', 'courriel'],
      'nom' => ['nom', 'lastname', 'name', 'famille'],
      'prenom' => ['prenom', 'prénom', 'firstname', 'first name'],
      'telephone' => ['telephone', 'téléphone', 'tel', 'mobile', 'portable', 'phone'],
      'civilite' => ['civilite', 'civilité', 'titre', 'civ'],
      'dateNaissance' => ['date naissance', 'naissance', 'birthdate', 'date de naissance'],
      'adresse' => ['adresse', 'address', 'rue'],
      'complement' => ['complement', 'complément', 'adresse 2', 'address 2'],
      'codePostal' => ['code postal', 'cp', 'postal code', 'zipcode'],
      'ville' => ['ville', 'city'],
      'pays' => ['pays', 'country'],
      'region' => ['region', 'région'],
      'departement' => ['departement', 'département', 'dept', 'dep'],
      'societe' => ['societe', 'société', 'company', 'entreprise'],
      'couleur' => ['couleur', 'color', 'hex'],
      'roles' => ['roles', 'role', 'profils', 'profil'],
      'fonction' => ['fonction', 'poste', 'job', 'metier', 'métier', 'role interne'],
      'tenant_roles' => ['tenant_roles', 'roles tenant', 'roles_tenant', 'profil tenant', 'profil_tenant', 'tenant role'],
      'status' => ['status', 'statut'],
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
    // Compatible PhpSpreadsheet récents
    return $sheet->getCell([$col, $row]);
  }

  private function extractRowData(Worksheet $sheet, int $row, array $colMap): array
  {
    $get = function (string $field) use ($sheet, $row, $colMap): ?string {
      if (!isset($colMap[$field])) return null;

      $col  = (int) $colMap[$field];
      $cell = $this->cell($sheet, $col, $row);

      if ($field === 'dateNaissance') {
        $dt = $this->parseDateCell($cell);
        return $dt?->format('Y-m-d');
      }

      $raw = $cell->getValue();
      if ($raw === null) return null;

      return trim((string) $cell->getFormattedValue());
    };

    return [
      'email' => $get('email'),
      'nom' => $get('nom'),
      'prenom' => $get('prenom'),
      'telephone' => $get('telephone'),
      'civilite' => $get('civilite'),
      'dateNaissance' => $get('dateNaissance'),
      'adresse' => $get('adresse'),
      'complement' => $get('complement'),
      'codePostal' => $get('codePostal'),
      'ville' => $get('ville'),
      'pays' => $get('pays'),
      'region' => $get('region'),
      'departement' => $get('departement'),
      'societe' => $get('societe'),
      'couleur' => $get('couleur'),
      'roles' => $get('roles'),
      'fonction' => $get('fonction'),
      'tenant_roles' => $get('tenant_roles'),
      'status' => $get('status'),
    ];
  }

  /** @return list<string> */
  private function parseTenantRoles(?string $cell): array
  {
    if (!$cell) return [UtilisateurEntite::TENANT_EMPLOYE];

    $parts = preg_split('/[,\s;|]+/', trim($cell)) ?: [];
    $roles = [];

    foreach ($parts as $p) {
      $p = strtoupper(trim($p));
      if ($p === '') continue;

      // tolérances : "EMPLOYE", "TENANT_EMPLOYE", "ADMIN", "TENANT_ADMIN"
      if (!str_starts_with($p, 'TENANT_')) {
        $p = 'TENANT_' . $p;
      }

      if (in_array($p, [UtilisateurEntite::TENANT_EMPLOYE, UtilisateurEntite::TENANT_ADMIN], true)) {
        $roles[] = $p;
      }
    }

    $roles[] = UtilisateurEntite::TENANT_EMPLOYE; // sécurité
    return array_values(array_unique($roles));
  }

  private function normalizeStatus(?string $status): string
  {
    $s = mb_strtolower(trim((string) $status));
    return match ($s) {
      UtilisateurEntite::STATUS_INVITED => UtilisateurEntite::STATUS_INVITED,
      UtilisateurEntite::STATUS_SUSPENDED => UtilisateurEntite::STATUS_SUSPENDED,
      default => UtilisateurEntite::STATUS_ACTIVE,
    };
  }


  private function upsertUtilisateurEntite(
    Utilisateur $user,
    Entite $entite,
    Utilisateur $createur,
    array $data
  ): UtilisateurEntite {
    $ue = $this->userEntiteRepo->findOneBy(['utilisateur' => $user, 'entite' => $entite]);

    if (!$ue) {
      $ue = new UtilisateurEntite();
      $ue->setUtilisateur($user);
      $ue->setEntite($entite);
      $ue->setCreateur($createur);
      $ue->setStatus(UtilisateurEntite::STATUS_ACTIVE);
      $ue->ensureCouleur();
    }

    // ✅ couleur : priorité au CSV si valide, sinon existant, sinon couleur pool
    if (!empty($data['couleur'])) {
      $ue->setCouleur($this->normalizeColor($data['couleur']));
    }
    $ue->ensureCouleur();

    // ✅ fonction
    if (!empty($data['fonction'])) {
      $ue->setFonction(trim((string)$data['fonction']));
    }

    // ✅ status
    if (array_key_exists('status', $data)) {
      $ue->setStatus($this->normalizeStatus($data['status'] ?? null));
    }

    // ✅ roles tenant : colonne dédiée tenant_roles, sinon fallback : si l’utilisateur a ROLE_ADMIN => TENANT_ADMIN
    $tenantRoles = null;

    if (!empty($data['tenant_roles'])) {
      $tenantRoles = $this->parseTenantRoles($data['tenant_roles']);
    } else {
      $tenantRoles = [UtilisateurEntite::TENANT_EMPLOYE];
      if ($user->hasRole('ROLE_ADMIN') || $user->hasRole('ROLE_SUPER_ADMIN')) {
        $tenantRoles[] = UtilisateurEntite::TENANT_ADMIN;
      }
    }

    $ue->setRoles($tenantRoles);

    $this->em->persist($ue);

    return $ue;
  }

  private function parseDateCell(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): ?\DateTimeImmutable
  {
    $v = $cell->getValue();

    if (is_numeric($v)) {
      try {
        $dt = XlsDate::excelToDateTimeObject((float) $v);
        return \DateTimeImmutable::createFromMutable($dt);
      } catch (\Throwable) {
      }
    }

    $s = trim((string) $cell->getFormattedValue());
    if ($s === '') return null;

    $formats = ['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd-m-y', 'm/d/Y', 'm/d/y'];
    foreach ($formats as $f) {
      $dt = \DateTimeImmutable::createFromFormat($f, $s);
      if ($dt instanceof \DateTimeImmutable) return $dt;
    }

    try {
      return new \DateTimeImmutable($s);
    } catch (\Throwable) {
      return null;
    }
  }

  private function normalizeEmail(?string $email): ?string
  {
    if (!$email) return null;
    $email = trim(mb_strtolower($email));
    $email = str_replace(' ', '', $email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
  }

  /** @return list<string> */
  private function parseRoles(?string $rolesCell): array
  {
    if (!$rolesCell) return ['ROLE_USER'];

    $parts = preg_split('/[,\s;|]+/', trim($rolesCell)) ?: [];
    $roles = [];
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === '') continue;
      if (!str_starts_with($p, 'ROLE_')) $p = 'ROLE_' . strtoupper($p);
      $roles[] = $p;
    }
    $roles[] = 'ROLE_USER';
    return array_values(array_unique($roles));
  }

  private function normalizeColor(?string $hex): ?string
  {
    if (!$hex) return null;
    $hex = trim($hex);
    if ($hex === '') return null;
    if ($hex[0] !== '#') $hex = '#' . $hex;
    $hex = strtoupper($hex);
    return preg_match('/^#[0-9A-F]{6}$/', $hex) ? $hex : null;
  }

  private function normalizePhone(?string $tel): ?string
  {
    if (!$tel) return null;
    $tel = trim($tel);
    if ($tel === '') return null;

    $tel = preg_replace('/(?!^\+)[^\d]/', '', $tel) ?: $tel;

    if (preg_match('/^0\d{9}$/', $tel)) {
      $tel = '+33' . substr($tel, 1);
    }

    return $tel;
  }

  private function applyData(Utilisateur $u, array $data): void
  {
    if (!empty($data['nom'])) $u->setNom((string) $data['nom']);
    if (!empty($data['prenom'])) $u->setPrenom((string) $data['prenom']);

    if (array_key_exists('telephone', $data)) {
      $u->setTelephone($this->normalizePhone($data['telephone']));
    }

    if (array_key_exists('civilite', $data)) {
      $c = trim((string)($data['civilite'] ?? ''));
      $u->setCivilite($c !== '' ? $c : null);
    }

    if (!empty($data['adresse'])) $u->setAdresse((string) $data['adresse']);
    if (!empty($data['complement'])) $u->setComplement((string) $data['complement']);
    if (!empty($data['codePostal'])) $u->setCodePostal((string) $data['codePostal']);
    if (!empty($data['ville'])) $u->setVille((string) $data['ville']);
    if (!empty($data['pays'])) $u->setPays((string) $data['pays']);
    if (!empty($data['region'])) $u->setRegion((string) $data['region']);
    if (!empty($data['departement'])) $u->setDepartement((string) $data['departement']);
    if (!empty($data['societe'])) $u->setSociete((string) $data['societe']);

    if (array_key_exists('couleur', $data)) {
      $u->setCouleur($this->normalizeColor($data['couleur']));
    }

    if (!empty($data['dateNaissance'])) {
      try {
        $u->setDateNaissance(new \DateTimeImmutable((string) $data['dateNaissance']));
      } catch (\Throwable) { /* silencieux */
      }
    }
  }
}
