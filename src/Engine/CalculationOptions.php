<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

final readonly class CalculationOptions
{
    public function __construct(
        public bool $includeIntervals = false,
    ) {
    }
}
