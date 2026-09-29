<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Model\TimeRange;

final readonly class PeriodicChargeCalculation
{
    public function __construct(
        public string $units,
        public string $amount,
        /** @var list<array{range: TimeRange, units: string, amount: string}> */
        public array $buckets,
    ) {
    }
}
