<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

final readonly class CostPlanDefinition
{
    /** @param list<CostPlanEntry> $entries */
    public function __construct(
        public int $version,
        public array $entries,
    ) {
    }
}
