<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Contract;

use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\TimeRange;

interface EnergyDeltaSource
{
    /**
     * Must return deltas ordered by interval start.
     *
     * @return iterable<EnergyDelta>
     */
    public function getDeltas(string $meterId, TimeRange $range): iterable;
}
