<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Rate;

use Supla\EnergyCostCalculator\Definition\RateDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

final class DefaultRateResolver implements RateResolver
{
    public function resolve(
        EnergyDelta $delta,
        RateDefinition $definition,
        ?string $selection,
        ReferenceDataCache $references,
        DecimalMath $math,
    ): string {
        return match ($definition->type) {
            'CONSTANT' => (string)$definition->config['value'],
            'ZONED' => $this->resolveZoned($definition, $selection),
            'REFERENCE' => $this->resolveReference($delta, $definition, $references, $math),
            default => throw new CalculationException("Unsupported rate type {$definition->type}."),
        };
    }

    private function resolveZoned(RateDefinition $definition, ?string $selection): string
    {
        if ($selection === null) {
            throw new CalculationException('ZONED rate requires a selector result.');
        }
        $rates = $definition->config['rates'];
        if (!array_key_exists($selection, $rates)) {
            throw new CalculationException("No rate configured for zone '$selection'.");
        }
        return (string)$rates[$selection];
    }

    private function resolveReference(
        EnergyDelta $delta,
        RateDefinition $definition,
        ReferenceDataCache $references,
        DecimalMath $math,
    ): string {
        $source = (string)$definition->config['source'];
        $value = $references->series($source)->valueAt($delta->from)->value;
        $multiplier = (string)($definition->config['multiplier'] ?? '1');
        $add = (string)($definition->config['add'] ?? '0');
        return $math->add($math->multiply($value, $multiplier), $add);
    }
}
