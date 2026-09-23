<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

final readonly class CostPlanComponent
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public CostComponentKind $kind,
        public ?string $presetId = null,
        public ?string $componentId = null,
        public array $values = [],
        public ?string $rate = null,
        public ?string $per = null,
        public bool $prorate = false,
    ) {
    }
}
