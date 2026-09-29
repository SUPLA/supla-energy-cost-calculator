<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

use Supla\EnergyCostCalculator\Tax\TaxContext;

final readonly class CostPlanDefinition
{
    /** @param list<array<string, mixed>> $billingCycles @param list<CostPlanPeriod> $periods */
    public function __construct(
        public array $billingCycles,
        public string $currency,
        public string $timezone,
        public ?TaxContext $taxContext,
        public array $periods,
    ) {
    }
}
