<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\BillingCyclePeriodDefinition;
use Supla\EnergyCostCalculator\Model\TimeRange;

final readonly class ResolvedBillingPeriod
{
    public function __construct(
        public TimeRange $range,
        public TimeRange $nominalRange,
        public BillingCyclePeriodDefinition $definition,
    ) {
    }

    public function isTransitional(): bool
    {
        return $this->range->from->getTimestamp() !== $this->nominalRange->from->getTimestamp()
            || $this->range->to->getTimestamp() !== $this->nominalRange->to->getTimestamp();
    }
}
