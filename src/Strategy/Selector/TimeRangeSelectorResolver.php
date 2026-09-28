<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Selector;

use Supla\EnergyCostCalculator\Definition\SelectorDefinition;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

interface TimeRangeSelectorResolver
{
    public function resolveRange(
        TimeRange $range,
        SelectorDefinition $definition,
        ReferenceDataCache $references,
    ): ?string;
}
