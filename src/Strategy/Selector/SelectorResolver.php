<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Selector;

use Supla\EnergyCostCalculator\Definition\SelectorDefinition;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

interface SelectorResolver
{
    public function resolve(EnergyDelta $delta, SelectorDefinition $definition, ReferenceDataCache $references): ?string;
}
