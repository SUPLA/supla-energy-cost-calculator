<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Model;

use InvalidArgumentException;

final readonly class EnergyDelta
{
    /**
     * @param array<string, string> $quantities Decimal values in the unit implied by the quantity type, normally kWh.
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $quantities,
    ) {
        if ($from >= $to) {
            throw new InvalidArgumentException('EnergyDelta.from must be before EnergyDelta.to.');
        }
    }

    public function quantity(QuantityType $type): ?string
    {
        return $this->quantities[$type->value] ?? null;
    }

    public function range(): TimeRange
    {
        return new TimeRange($this->from, $this->to);
    }
}
