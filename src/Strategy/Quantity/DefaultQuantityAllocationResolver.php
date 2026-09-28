<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Quantity;

use Supla\EnergyCostCalculator\Definition\QuantityAllocationDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\QuantityAllocationStrategy;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class DefaultQuantityAllocationResolver implements QuantityAllocationResolver
{
    public function resolve(
        TimeRange $window,
        string $value,
        QuantityAllocationDefinition $definition,
        DecimalMath $math,
    ): array {
        if ($definition->strategy !== QuantityAllocationStrategy::EQUAL) {
            throw new CalculationException("Unsupported quantity allocation strategy '{$definition->strategy->value}'.");
        }

        $slotSeconds = $definition->periodInMinutes * 60;
        $windowSeconds = $window->to->getTimestamp() - $window->from->getTimestamp();
        if ($slotSeconds < 1 || $windowSeconds <= $slotSeconds || $windowSeconds % $slotSeconds !== 0) {
            throw new CalculationException(sprintf(
                'Allocation period %d minutes does not divide quantity window %s..%s into shorter complete slots.',
                $definition->periodInMinutes,
                $window->from->format(DATE_ATOM),
                $window->to->format(DATE_ATOM),
            ));
        }

        $count = intdiv($windowSeconds, $slotSeconds);
        $slotValue = $math->divide($value, (string)$count);
        $timezone = $window->from->getTimezone();
        if ($timezone === false) {
            throw new CalculationException('Quantity allocation requires a timezone-aware window.');
        }

        $allocations = [];
        for ($index = 0; $index < $count; $index++) {
            $fromTimestamp = $window->from->getTimestamp() + ($index * $slotSeconds);
            $toTimestamp = $fromTimestamp + $slotSeconds;
            $from = (new \DateTimeImmutable('@' . $fromTimestamp))->setTimezone($timezone);
            $to = (new \DateTimeImmutable('@' . $toTimestamp))->setTimezone($timezone);
            $allocations[] = new QuantityAllocation(new TimeRange($from, $to), $slotValue, $index, $count);
        }

        return $allocations;
    }
}
