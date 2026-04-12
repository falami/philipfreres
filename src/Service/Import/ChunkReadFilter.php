<?php
// src/Service/Import/ChunkReadFilter.php

declare(strict_types=1);

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final class ChunkReadFilter implements IReadFilter
{
  public function __construct(
    private readonly int $startRow,
    private readonly int $endRow,
    private readonly ?string $sheetName = null, // optionnel: filtrer une feuille précise
  ) {}

  public function readCell($column, $row, $worksheetName = ''): bool
  {
    if ($this->sheetName !== null && $worksheetName !== $this->sheetName) {
      return false;
    }

    return $row >= $this->startRow && $row <= $this->endRow;
  }
}
