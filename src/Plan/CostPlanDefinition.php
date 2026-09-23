<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

final readonly class CostPlanDefinition
{
    /**
     * @param list<CostPlanEntry> $entries
     * @param list<array<string, mixed>> $billingCycles
     * @param list<CostPlanPeriod> $periods
     */
    public function __construct(
        public int $version,
        public array $entries,
        public array $billingCycles = [],
        public ?string $currency = null,
        public ?string $timezone = null,
        public ?string $priceBasis = null,
        public array $periods = [],
    ) {
    }
}
