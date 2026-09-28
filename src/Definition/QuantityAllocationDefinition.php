<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

use Supla\EnergyCostCalculator\Model\QuantityAllocationStrategy;

final readonly class QuantityAllocationDefinition
{
    public function __construct(
        public QuantityAllocationStrategy $strategy,
        public int $periodInMinutes,
    ) {
    }
}
