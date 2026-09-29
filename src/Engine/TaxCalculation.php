<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

final readonly class TaxCalculation
{
    /** @param array<string, array<string, mixed>> $taxes */
    public function __construct(
        public string $taxExclusive,
        public array $taxes,
        public string $taxTotal,
        public string $taxInclusive,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'taxExclusive' => $this->taxExclusive,
            'taxes' => $this->taxes,
            'taxTotal' => $this->taxTotal,
            'taxInclusive' => $this->taxInclusive,
        ];
    }
}
