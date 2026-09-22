<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

final readonly class CostPlanEntry
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo,
        public string $presetId,
        public array $values,
    ) {
    }
}
