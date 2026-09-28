<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Quantity;

use Supla\EnergyCostCalculator\Model\TimeRange;

final readonly class QuantityAllocation
{
    public function __construct(
        public TimeRange $range,
        public string $value,
        public int $index,
        public int $count,
    ) {
    }
}
