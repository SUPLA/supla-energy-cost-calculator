<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Rate;

use Supla\EnergyCostCalculator\Definition\RateDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

final class DefaultRateResolver implements RateResolver, TimeRangeRateResolver
{
    public function resolve(
        EnergyDelta $delta,
        RateDefinition $definition,
        ?string $selection,
        ReferenceDataCache $references,
        DecimalMath $math,
    ): string {
        return $this->resolveAt($delta->from, null, $definition, $selection, $references, $math);
    }

    public function resolveRange(
        TimeRange $range,
        RateDefinition $definition,
        ?string $selection,
        ReferenceDataCache $references,
        DecimalMath $math,
    ): string {
        return $this->resolveAt($range->from, $range, $definition, $selection, $references, $math);
    }

    private function resolveAt(
        \DateTimeImmutable $timestamp,
        ?TimeRange $range,
        RateDefinition $definition,
        ?string $selection,
        ReferenceDataCache $references,
        DecimalMath $math,
    ): string {
        return match ($definition->type) {
            'CONSTANT' => (string)$definition->config['value'],
            'ZONED' => $this->resolveZoned($definition, $selection),
            'REFERENCE' => $this->resolveReference($timestamp, $range, $definition, $references, $math),
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
        \DateTimeImmutable $timestamp,
        ?TimeRange $range,
        RateDefinition $definition,
        ReferenceDataCache $references,
        DecimalMath $math,
    ): string {
        $source = (string)$definition->config['source'];
        $interval = $references->series($source)->valueAt($timestamp);
        if ($range !== null && $interval->to < $range->to) {
            throw new CalculationException(sprintf(
                "Reference source '%s' changes inside pricing interval %s..%s.",
                $source,
                $range->from->format(DATE_ATOM),
                $range->to->format(DATE_ATOM),
            ));
        }

        $sourceUnit = $definition->config['sourceUnit'] ?? null;
        if ($sourceUnit !== null && $interval->unit !== $sourceUnit) {
            $actualUnit = $interval->unit ?? '<missing>';
            throw new CalculationException(sprintf(
                "Reference source '%s' unit mismatch: expected '%s', got '%s'.",
                $source,
                $sourceUnit,
                $actualUnit,
            ));
        }
        $value = $interval->value;
        $sourceMin = isset($definition->config['sourceMin']) ? (string)$definition->config['sourceMin'] : null;
        $sourceMax = isset($definition->config['sourceMax']) ? (string)$definition->config['sourceMax'] : null;
        if ($sourceMin !== null && $sourceMax !== null && $this->compare($math, $sourceMin, $sourceMax) > 0) {
            throw new CalculationException('REFERENCE sourceMin must be less than or equal to sourceMax.');
        }
        if ($sourceMin !== null && $this->compare($math, $value, $sourceMin) < 0) {
            $value = $sourceMin;
        }
        if ($sourceMax !== null && $this->compare($math, $value, $sourceMax) > 0) {
            $value = $sourceMax;
        }
        $multiplier = (string)($definition->config['multiplier'] ?? '1');
        $add = (string)($definition->config['add'] ?? '0');
        return $math->add($math->multiply($value, $multiplier), $add);
    }

    private function compare(DecimalMath $math, string $left, string $right): int
    {
        $difference = $math->add($left, $math->multiply($right, '-1'));
        if ((float)$difference === 0.0) {
            return 0;
        }
        return str_starts_with($difference, '-') ? -1 : 1;
    }
}
