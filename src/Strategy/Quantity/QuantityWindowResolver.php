<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Quantity;

use Supla\EnergyCostCalculator\Definition\QuantityDefinition;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\EnergyDelta;

interface QuantityWindowResolver
{
    /** @param list<EnergyDelta> $deltas */
    public function resolve(array $deltas, QuantityDefinition $definition, DecimalMath $math): QuantityWindowResolution;
}
