<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Model;

use InvalidArgumentException;

final readonly class EnergyLogDelta
{
    /**
     * @param array<string, int> $values Counter deltas for the interval.
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $values,
    ) {
        if ($from >= $to) {
            throw new InvalidArgumentException('EnergyLogDelta.from must be before EnergyLogDelta.to.');
        }
    }
}
