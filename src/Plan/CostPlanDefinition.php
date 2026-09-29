<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

final readonly class CostPlanDefinition
{
    /** @param list<array<string, mixed>> $billingCycles @param list<array<string, mixed>> $taxProfiles @param list<CostPlanPeriod> $periods */
    public function __construct(
        public array $billingCycles,
        public string $currency,
        public string $timezone,
        public array $taxProfiles,
        public array $periods,
    ) {
    }
}
