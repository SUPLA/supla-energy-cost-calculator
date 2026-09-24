<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

final readonly class CostPlanStarter
{
    /** @param array<string, mixed> $metadata @param array<string, mixed> $plan */
    public function __construct(
        public string $id,
        public string $revision,
        public array $metadata,
        public array $plan,
    ) {
    }
}
