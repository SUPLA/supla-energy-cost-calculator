<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

final readonly class BillingCycleDefinition implements \JsonSerializable
{
    public function __construct(
        public ?\DateTimeImmutable $anchor,
        public int $length,
        public BillingCycleUnit $unit,
    ) {
        if ($length < 1) {
            throw new \InvalidArgumentException('Billing cycle length must be positive.');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'anchor' => $this->anchor?->format(DATE_ATOM),
            'length' => $this->length,
            'unit' => $this->unit->value,
        ];
    }
}
