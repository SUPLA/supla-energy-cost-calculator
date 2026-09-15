<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Quantity;

use Supla\EnergyCostCalculator\Definition\QuantityDefinition;
use Supla\EnergyCostCalculator\Model\EnergyDelta;

interface QuantityResolver
{
    public function resolve(EnergyDelta $delta, QuantityDefinition $definition): string;
}
