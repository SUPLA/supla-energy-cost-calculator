<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

use Supla\EnergyCostCalculator\Model\QuantityStrategy;
use Supla\EnergyCostCalculator\Model\QuantityType;

final readonly class QuantityDefinition
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public QuantityType $type,
        public ?QuantityStrategy $strategy = null,
        public ?int $periodInMinutes = null,
        public array $options = [],
    ) {
    }

    public function usesTemporalNetting(): bool
    {
        return $this->strategy !== null;
    }
}
