<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

final readonly class CalculationResult implements \JsonSerializable
{
    /**
     * @param array<string, string> $byComponent
     * @param list<array<string, mixed>> $intervals
     */
    public function __construct(
        public string $currency,
        public string $total,
        public array $byComponent,
        public int $processedDeltaCount,
        public array $intervals = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'currency' => $this->currency,
            'total' => $this->total,
            'byComponent' => $this->byComponent,
            'processedDeltaCount' => $this->processedDeltaCount,
            'intervals' => $this->intervals,
        ];
    }
}
