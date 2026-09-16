<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

final readonly class PeriodicChargeCalculation
{
    public function __construct(
        public string $units,
        public string $amount,
    ) {
    }
}
