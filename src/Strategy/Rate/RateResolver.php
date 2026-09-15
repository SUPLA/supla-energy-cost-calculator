<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Rate;

use Supla\EnergyCostCalculator\Definition\RateDefinition;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

interface RateResolver
{
    public function resolve(
        EnergyDelta $delta,
        RateDefinition $definition,
        ?string $selection,
        ReferenceDataCache $references,
        DecimalMath $math,
    ): string;
}
