<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Contract;

use DateTimeImmutable;
use Supla\EnergyCostCalculator\Model\EnergyLog;

interface EnergyLogSource
{
    /**
     * Returns readings ordered by timestamp. When $after is provided, the
     * reading at or immediately before it must be returned as a baseline.
     *
     * @return iterable<EnergyLog>
     */
    public function getLogs(string $meterId, ?DateTimeImmutable $after, int $limit): iterable;
}
