<?php

namespace App\Service\Carburant;

use App\Dto\Carburant\FuelDashboardFilters;
use App\Repository\Carburant\FuelAnalyticsRepository;

final class FuelDashboardService
{
  public function __construct(
    private readonly FuelAnalyticsRepository $repo,
  ) {}

  public function getPlanningMatrix(int $entiteId, FuelDashboardFilters $filters): array
  {
    return $this->repo->fetchPlanningMatrix($entiteId, $filters);
  }
}
