<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Rate;

use Supla\EnergyCostCalculator\Definition\RateDefinition;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

interface TimeRangeRateResolver
{
    public function resolveRange(
        TimeRange $range,
        RateDefinition $definition,
        ?string $selection,
        ReferenceDataCache $references,
        DecimalMath $math,
    ): string;
}
