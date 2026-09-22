<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Quantity;

final readonly class QuantityWindowResolution
{
    /** @param array<string, string> $sourceQuantities */
    public function __construct(
        public string $value,
        public array $sourceQuantities,
    ) {
    }
}
