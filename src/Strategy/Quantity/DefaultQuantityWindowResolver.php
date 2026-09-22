<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Quantity;

use Supla\EnergyCostCalculator\Definition\QuantityDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityStrategy;
use Supla\EnergyCostCalculator\Model\QuantityType;

final class DefaultQuantityWindowResolver implements QuantityWindowResolver
{
    public function resolve(array $deltas, QuantityDefinition $definition, DecimalMath $math): QuantityWindowResolution
    {
        if ($definition->strategy === null || $definition->periodInMinutes === null) {
            throw new CalculationException('Quantity window resolution requires a strategy and periodInMinutes.');
        }
        if ($definition->type !== QuantityType::ACTIVE_ENERGY_IMPORT) {
            throw new CalculationException('Temporal netting currently supports ACTIVE_ENERGY_IMPORT only.');
        }
        if ($deltas === []) {
            throw new CalculationException('Quantity window cannot be resolved without meter deltas.');
        }

        $import = '0';
        $export = '0';
        foreach ($deltas as $delta) {
            $deltaImport = $delta->quantity(QuantityType::ACTIVE_ENERGY_IMPORT);
            $deltaExport = $delta->quantity(QuantityType::ACTIVE_ENERGY_EXPORT);
            if ($deltaImport === null || $deltaExport === null) {
                throw new CalculationException(sprintf(
                    'Energy delta %s..%s must contain ACTIVE_ENERGY_IMPORT and ACTIVE_ENERGY_EXPORT for netting.',
                    $delta->from->format(DATE_ATOM),
                    $delta->to->format(DATE_ATOM),
                ));
            }
            $import = $math->add($import, $deltaImport);
            $export = $math->add($export, $deltaExport);
        }

        $net = $math->add($import, $math->multiply($export, '-1'));
        if ($definition->strategy === QuantityStrategy::IMPORT_MINUS_EXPORT_CAP_ZERO && str_starts_with($net, '-')) {
            $net = '0';
        }

        return new QuantityWindowResolution($net, [
            QuantityType::ACTIVE_ENERGY_IMPORT->value => $import,
            QuantityType::ACTIVE_ENERGY_EXPORT->value => $export,
        ]);
    }
}
