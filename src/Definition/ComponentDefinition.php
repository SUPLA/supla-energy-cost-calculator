<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

final readonly class ComponentDefinition
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $category,
        public QuantityDefinition $quantity,
        public SelectorDefinition $selector,
        public RateDefinition $rate,
        public TaxTreatment $taxTreatment,
    ) {
    }

    public function isPeriodic(): bool
    {
        return $this->quantity->type->value === 'PERIOD';
    }
}
