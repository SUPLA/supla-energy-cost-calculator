<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

final readonly class TaxCalculation
{
    /** @param array<string, array<string, mixed>> $taxes */
    public function __construct(
        public string $net,
        public array $taxes,
        public string $taxTotal,
        public string $gross,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'net' => $this->net,
            'taxes' => $this->taxes,
            'taxTotal' => $this->taxTotal,
            'gross' => $this->gross,
        ];
    }
}
