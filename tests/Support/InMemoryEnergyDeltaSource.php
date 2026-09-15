<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Support;

use Supla\EnergyCostCalculator\Contract\EnergyDeltaSource;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class InMemoryEnergyDeltaSource implements EnergyDeltaSource
{
    /** @param list<EnergyDelta> $deltas */
    public function __construct(private readonly array $deltas)
    {
    }

    public function getDeltas(string $meterId, TimeRange $range): iterable
    {
        foreach ($this->deltas as $delta) {
            if ($delta->range()->overlaps($range)) {
                yield $delta;
            }
        }
    }
}
