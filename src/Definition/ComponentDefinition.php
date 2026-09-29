<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

use Supla\EnergyCostCalculator\Model\CostComponentKind;

final readonly class ComponentDefinition
{
    public function __construct(
        public string $id,
        public CostComponentKind $kind,
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
