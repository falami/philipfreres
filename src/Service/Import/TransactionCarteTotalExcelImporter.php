<?php
// src/Service/Import/TransactionCarteTotalExcelImporter.php

declare(strict_types=1);

namespace App\Service\Import;

use App\Service\Import\ChunkReadFilter;

use App\Entity\Entite;
use App\Entity\TransactionCarteTotal;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Enum\ExternalProvider;

final class TransactionCarteTotalExcelImporter
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
    // (optionnel) pour sécuriser en environnement web
    @ini_set('max_execution_time', '300');
    @set_time_limit(300);

    $path = $file->getPathname();
    $filename = $file->getClientOriginalName() ?: 'import.xlsx';

    $repo = $this->em->getRepository(TransactionCarteTotal::class);

    $imported = 0;
    $skipped = 0;
    $errors = [];

    // ===== 0) Prépare le reader (léger) =====
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $reader->setReadEmptyCells(false);

    $reader->setLoadSheetsOnly(['Toutes-les-transactions']);

    // ===== 1) Charge uniquement les 250 premières lignes pour détecter entêtes =====
    $reader->setReadFilter(new ChunkReadFilter(1, 250));
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();

    [$headerRow, $colMap] = $this->detectHeaderRowAndMap($sheet);

    // Important : libère mémoire
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    gc_collect_cycles();

    if ($headerRow === null) {
      return [
        'imported' => 0,
        'skipped' => 0,
        'errors' => ["Entêtes introuvables : le fichier ne ressemble pas au format attendu."],
      ];
    }

    // ===== 2) Déterminer le vrai maxRow SANS charger tout le fichier =====
    // On scanne par blocs (rapide) pour trouver la dernière ligne non vide
    $maxRow = $this->detectMaxRowByChunks($reader, $path, $headerRow, $colMap);

    if ($maxRow <= $headerRow) {
      return [
        'imported' => 0,
        'skipped' => 0,
        'errors' => ["Aucune ligne de données détectée après les entêtes."],
      ];
    }

    // ===== 3) Import par chunks =====
    $chunkSize = 500;
    $batchSize = 200;

    // Petite optimisation : anti-doublons en mémoire pour le fichier courant
    // (évite 3000 requêtes findOneBy)
    $seenKeys = [];

    try {
      $this->em->beginTransaction();

      for ($start = $headerRow + 1; $start <= $maxRow; $start += $chunkSize) {
        $end = min($start + $chunkSize - 1, $maxRow);

        $reader->setReadFilter(new ChunkReadFilter($start, $end));
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $entiteRef = $this->em->getReference(Entite::class, $entite->getId());

        $batch = 0;

        for ($r = $start; $r <= $end; $r++) {
          // si la ligne est vide -> skip
          if ($this->isRowEmpty($sheet, $r, $colMap)) {
            continue;
          }

          try {
            $row = $this->readRow($sheet, $r, $colMap);
            $importKey = $this->buildImportKey($entite->getId(), $row);

            // anti doublon fichier
            if (isset($seenKeys[$importKey])) {
              $skipped++;
              continue;
            }
            $seenKeys[$importKey] = true;

            // anti doublon base
            $exists = $repo->findOneBy(['entite' => $entiteRef, 'importKey' => $importKey]);
            if ($exists) {
              $skipped++;
              continue;
            }

            $t = (new TransactionCarteTotal())
              ->setEntite($entiteRef) // ✅ IMPORTANT
              ->setSourceFilename($filename)
              ->setSourceRow($r)
              ->setImportKey($importKey);

            // Champs texte
            $this->setIf($t, 'compteClient', $row['compte_client'] ?? null);
            $this->setIf($t, 'raisonSociale', $row['raison_sociale'] ?? null);
            $this->setIf($t, 'compteSupport', $row['compte_support'] ?? null);
            $this->setIf($t, 'division', $row['division'] ?? null);
            $this->setIf($t, 'typeSupport', $row['type_de_support'] ?? null);
            $this->setIf($t, 'numeroCarte', $row['numero_de_carte'] ?? null);
            $this->setIf($t, 'rang', $row['rang'] ?? null);
            $this->setIf($t, 'evid', $row['evid'] ?? null);
            $this->setIf($t, 'nomPersonnaliseCarte', $row['nom_personnalise_imprime_sur_la_carte'] ?? null);
            $this->setIf($t, 'informationComplementaire', $row['information_complementaire'] ?? null);
            $this->setIf($t, 'codeConducteur', $row['code_conducteur'] ?? null);
            $this->setIf($t, 'immatriculationVehicule', $row['immatriculation_vehicule'] ?? null);
            $this->setIf($t, 'nomCollaborateur', $row['nom_collaborateur'] ?? null);
            $this->setIf($t, 'prenomCollaborateur', $row['prenom_collaborateur'] ?? null);
            $this->setIf($t, 'numeroTransaction', $row['numero_de_transaction'] ?? null);
            $this->setIf($t, 'pays', $row['pays'] ?? null);
            $this->setIf($t, 'ville', $row['ville'] ?? null);
            $this->setIf($t, 'codePostal', $row['code_postal'] ?? null);
            $this->setIf($t, 'adresse', $row['adresse'] ?? null);
            $this->setIf($t, 'categorieLibelleProduit', $row['categorie_libelle_produit'] ?? null);
            $this->setIf($t, 'produit', $row['produit'] ?? null);
            $this->setIf($t, 'statut', $row['statut'] ?? null);
            $this->setIf($t, 'numeroFacture', $row['numero_de_facture'] ?? null);
            $this->setIf($t, 'unite', $row['unite'] ?? null);

            // Numériques
            if (($km = $this->toInt($row['kilometrage'] ?? null)) !== null) {
              $t->setKilometrage($km);
            }

            // Date / heure
            if (($d = $this->toDateImmutable($row['date'] ?? null)) !== null) {
              $t->setDateTransaction($d);
            }
            if (($h = $this->toTimeImmutable($row['heure'] ?? null)) !== null) {
              $t->setHeureTransaction($h);
            }

            // Décimaux
            $t->setQuantite($this->toDecimalString($row['quantite'] ?? null, 3));
            $t->setPrixUnitaireEur($this->toDecimalString($row['prix_unitaire_eur'] ?? null, 4));
            $t->setTauxTvaPercent($this->toTvaPercentString($row['taux_de_tva_percent'] ?? null));
            $t->setMontantRemiseEur($this->toDecimalString($row['montant_remise_eur'] ?? null, 2));
            $t->setMontantHtEur($this->toDecimalString($row['montant_ht_eur'] ?? null, 2));
            $t->setMontantTvaEur($this->toDecimalString($row['montant_tva_eur'] ?? null, 2));
            $t->setMontantTtcEur($this->toDecimalString($row['montant_ttc_eur'] ?? null, 2));
            $t->setProvider(ExternalProvider::TOTAL);

            $this->resolver->resolveTotal($entite, $t, false);
            $this->em->persist($t);
            $imported++;
            $batch++;

            if ($batch >= $batchSize) {
              $this->em->flush();
              $this->em->clear(TransactionCarteTotal::class);

              // ✅ Recrée une référence managée après clear
              $entiteRef = $this->em->getReference(Entite::class, $entite->getId());

              $batch = 0;
            }
          } catch (\Throwable $e) {
            $errors[] = "Ligne {$r}: " . $e->getMessage();
            $skipped++;
          }
        }

        // flush du chunk
        $this->em->flush();
        $this->em->clear(TransactionCarteTotal::class);

        // libère le spreadsheet du chunk
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


  private function detectMaxRowByChunks(
    \PhpOffice\PhpSpreadsheet\Reader\IReader $reader,
    string $path,
    int $headerRow,
    array $colMap
  ): int {
    // On cherche la dernière ligne “non vide” en avançant par blocs
    $chunk = 1000;
    $lastNonEmpty = $headerRow;

    // On scanne jusqu’à trouver 2 blocs d’affilée vides (stop sûr)
    $emptyBlocks = 0;

    for ($start = $headerRow + 1; $start <= 2000000; $start += $chunk) { // plafond “très large”
      $end = $start + $chunk - 1;

      $reader->setReadFilter(new ChunkReadFilter($start, $end));
      $spreadsheet = $reader->load($path);
      $sheet = $spreadsheet->getActiveSheet();

      $foundInThisBlock = false;
      for ($r = $start; $r <= $end; $r++) {
        if (!$this->isRowEmpty($sheet, $r, $colMap)) {
          $lastNonEmpty = $r;
          $foundInThisBlock = true;
        }
      }

      $spreadsheet->disconnectWorksheets();
      unset($spreadsheet);
      gc_collect_cycles();

      if ($foundInThisBlock) {
        $emptyBlocks = 0;
        continue;
      }

      $emptyBlocks++;
      if ($emptyBlocks >= 2) {
        break;
      }
    }

    return $lastNonEmpty;
  }

  // ===================== Header detection =====================

  /**
   * @return array{0:?int, 1:array<string,int>} headerRow + colMap(key=>colIndex)
   */


  private function detectHeaderRowAndMap(Worksheet $sheet): array
  {
    $expected = [
      'compte_client' => ['compte client'],
      'raison_sociale' => ['raison sociale'],
      'compte_support' => ['compte support'],
      'division' => ['division'],
      'type_de_support' => ['type de support'],
      'numero_de_carte' => ['numéro de carte', 'numero de carte'],
      'rang' => ['rang'],
      'evid' => ['evid'],
      'nom_personnalise_imprime_sur_la_carte' => ['nom personnalisé, imprimé sur la carte', 'nom personnalise, imprime sur la carte'],
      'information_complementaire' => ['information complémentaire', 'information complementaire'],
      'code_conducteur' => ['code chauffeur'],
      'immatriculation_vehicule' => ['immatriculation véhicule', 'immatriculation vehicule'],
      'nom_collaborateur' => ['nom collaborateur'],
      'prenom_collaborateur' => ['prénom collaborateur', 'prenom collaborateur'],
      'kilometrage' => ['kilométrage', 'kilometrage'],
      'numero_de_transaction' => ['numéro de transaction', 'numero de transaction'],
      'date' => ['date'],
      'heure' => ['heure'],
      'pays' => ['pays'],
      'ville' => ['ville'],
      'code_postal' => ['code postal'],
      'adresse' => ['adresse'],
      'categorie_libelle_produit' => ['catégorie / libellé produit', 'categorie / libelle produit'],
      'produit' => ['produit'],
      'statut' => ['statut'],
      'numero_de_facture' => ['numéro de facture', 'numero de facture'],
      'quantite' => ['quantité', 'quantite'],
      'unite' => ['unité', 'unite'],
      'prix_unitaire_eur' => ['prix unitaire - eur'],
      'taux_de_tva_percent' => ['taux de tva - %'],
      'montant_remise_eur' => ['montant remise - eur'],
      'montant_ht_eur' => ['montant ht - eur'],
      'montant_tva_eur' => ['montant tva - eur'],
      'montant_ttc_eur' => ['montant ttc - eur'],
    ];

    // ✅ IMPORTANT : “Data” = fiable (ignore styles/format)
    $highestColIndex = max(
      Coordinate::columnIndexFromString($sheet->getHighestColumn()),
      Coordinate::columnIndexFromString($sheet->getHighestDataColumn())
    );

    $maxCol = max(1, min(200, $highestColIndex));

    $maxRow = min(
      200,
      max($sheet->getHighestRow(), $sheet->getHighestDataRow())
    );

    $bestRow = null;
    $bestScore = 0;
    $bestMap = [];

    // ✅ Fallback : on essaye de trouver “Compte client” dans les 120 premières lignes
    $forcedHeaderRow = $this->findRowContaining($sheet, $maxRow, $maxCol, 'compte client');

    // Si on l’a trouvé, on commence par cette ligne (en général = 5)
    $startRow = $forcedHeaderRow ?? 1;

    for ($r = $startRow; $r <= $maxRow; $r++) {
      $rowValues = [];

      for ($c = 1; $c <= $maxCol; $c++) {
        $v = $sheet->getCell([$c, $r])->getValue();
        if ($v === null || $v === '') continue;

        $rowValues[$c] = $this->normHeader((string) $v);
      }

      if (!$rowValues) continue;

      $map = [];
      $score = 0;

      foreach ($expected as $key => $labels) {
        $found = false;

        foreach ($rowValues as $c => $h) {
          foreach ($labels as $label) {
            $labelNorm = $this->normHeader($label);

            if ($h === $labelNorm || str_contains($h, $labelNorm) || str_contains($labelNorm, $h)) {
              $map[$key] = $c;
              $score++;
              $found = true;
              break 2; // ✅ on sort seulement de (labels + rowValues), pas de expected
            }
          }
        }

        // optionnel : si pas trouvé, on continue juste
      }

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestRow = $r;
        $bestMap = $map;
      }

      // ✅ Si on a forcé une ligne et qu’elle score bien, on peut sortir tôt
      if ($forcedHeaderRow !== null && $r === $forcedHeaderRow && $score >= 8) {
        break;
      }
    }

    if ($bestScore < 8) {
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
        if ($h === $needle || str_contains($h, $needle)) {
          return $r;
        }
      }
    }
    return null;
  }

  private function normHeader(string $s): string
  {
    $s = trim(mb_strtolower($s));
    $s = str_replace(['’', "'"], ' ', $s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('~[^a-z0-9%/ -]+~', ' ', $s) ?: $s;
    $s = preg_replace('~\s+~', ' ', $s) ?: $s;
    return trim($s);
  }

  // ===================== Row reading / parsing =====================

  private function isRowEmpty($sheet, int $row, array $colMap): bool
  {
    // on teste quelques colonnes clés si présentes
    foreach (['date', 'produit', 'montant_ttc_eur', 'numero_de_carte'] as $k) {
      if (!isset($colMap[$k])) continue;
      $v = $sheet->getCell([$colMap[$k], $row])->getValue();
      if ($v !== null && $v !== '') return false;
    }
    return true;
  }

  /**
   * @return array<string,mixed>
   */
  private function readRow($sheet, int $row, array $colMap): array
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
      (string)($row['numero_de_transaction'] ?? ''),
      (string)($row['numero_de_carte'] ?? ''),
      (string)($row['date'] ?? ''),
      (string)($row['heure'] ?? ''),
      (string)($row['produit'] ?? ''),
      (string)($row['montant_ttc_eur'] ?? ''),
    ];
    return sha1(implode('|', $parts));
  }

  private function toInt(mixed $v): ?int
  {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (int) round((float)$v);
    $s = preg_replace('~[^0-9]~', '', (string)$v);
    return $s === '' ? null : (int)$s;
  }

  private function toDecimalString(mixed $v, int $scale): ?string
  {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
      return number_format((float)$v, $scale, '.', '');
    }
    $s = trim((string)$v);
    $s = str_replace([' ', "\u{00A0}"], '', $s);
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return number_format((float)$s, $scale, '.', '');
  }

  private function toTvaPercentString(mixed $v): ?string
  {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
      $n = (float)$v;
      // cas observé : 2000 => 20.00
      if ($n > 100 && fmod($n, 100) === 0.0) {
        $n = $n / 100;
      }
      return number_format($n, 2, '.', '');
    }
    return $this->toDecimalString($v, 2);
  }

  private function toDateImmutable(mixed $v): ?\DateTimeImmutable
  {
    if ($v === null || $v === '') return null;

    // date Excel numérique
    if (is_numeric($v)) {
      $dt = XlsDate::excelToDateTimeObject((float)$v);
      return \DateTimeImmutable::createFromMutable($dt)->setTime(0, 0, 0);
    }

    // format texte dd/mm/yyyy
    $s = trim((string)$v);
    $d = \DateTimeImmutable::createFromFormat('d/m/Y', $s) ?: \DateTimeImmutable::createFromFormat('Y-m-d', $s);
    return $d?->setTime(0, 0, 0);
  }

  private function toTimeImmutable(mixed $v): ?\DateTimeImmutable
  {
    if ($v === null || $v === '') return null;

    if (is_numeric($v)) {
      // heure Excel : fraction de jour
      $dt = XlsDate::excelToDateTimeObject((float)$v);
      return \DateTimeImmutable::createFromMutable($dt);
    }

    $s = trim((string)$v);
    $t = \DateTimeImmutable::createFromFormat('H:i:s', $s) ?: \DateTimeImmutable::createFromFormat('H:i', $s);
    return $t;
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
