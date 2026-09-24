<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

final readonly class CostPlanPeriod
{
    /** @param list<CostPlanComponent> $components */
    public function __construct(
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo,
        public array $components,
    ) {
    }
}
