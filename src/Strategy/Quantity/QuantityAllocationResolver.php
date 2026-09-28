<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Quantity;

use Supla\EnergyCostCalculator\Definition\QuantityAllocationDefinition;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\TimeRange;

interface QuantityAllocationResolver
{
    /** @return list<QuantityAllocation> */
    public function resolve(
        TimeRange $window,
        string $value,
        QuantityAllocationDefinition $definition,
        DecimalMath $math,
    ): array;
}
