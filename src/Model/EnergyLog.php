<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Model;

final readonly class EnergyLog
{
    /**
     * @param array<string, int|float|null> $values Cumulative meter counter values.
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public array $values,
    ) {
    }
}
