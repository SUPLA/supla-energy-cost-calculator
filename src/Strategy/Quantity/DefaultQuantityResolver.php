<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Quantity;

use Supla\EnergyCostCalculator\Definition\QuantityDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;

final class DefaultQuantityResolver implements QuantityResolver
{
    public function resolve(EnergyDelta $delta, QuantityDefinition $definition): string
    {
        if ($definition->type === QuantityType::PERIOD) {
            throw new CalculationException('PERIOD quantity is not resolved from meter deltas.');
        }
        if ($definition->usesTemporalNetting()) {
            throw new CalculationException('Temporal netting quantity must be resolved from a complete quantity window.');
        }

        $value = $delta->quantity($definition->type);
        if ($value === null) {
            throw new CalculationException(sprintf(
                'Energy delta %s..%s does not contain quantity %s.',
                $delta->from->format(DATE_ATOM),
                $delta->to->format(DATE_ATOM),
                $definition->type->value,
            ));
        }
        return $value;
    }
}
