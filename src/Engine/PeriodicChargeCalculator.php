<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\BillingCycleUnit;
use Supla\EnergyCostCalculator\Definition\ComponentDefinition;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class PeriodicChargeCalculator
{
    public function __construct(
        private readonly DecimalMath $math,
        private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver(),
    ) {
    }

    /**
     * @param list<ResolvedBillingPeriod> $billingPeriods
     */
    public function calculate(
        ComponentDefinition $component,
        TimeRange $applicableRange,
        array $billingPeriods,
    ): PeriodicChargeCalculation {
        $period = (string)$component->quantity->options['period'];
        $prorate = (bool)($component->quantity->options['prorate'] ?? false);
        $rate = (string)$component->rate->config['value'];
        $totalUnits = '0';

        foreach ($billingPeriods as $billingPeriod) {
            $effective = $billingPeriod->range->intersection($applicableRange);
            if ($effective === null) {
                continue;
            }

            if ($period === 'BILLING_PERIOD') {
                $units = $prorate
                    ? $this->fraction($effective, $billingPeriod->nominalRange)
                    : '1';
                $totalUnits = $this->math->add($totalUnits, $units);
                continue;
            }

            $unit = $this->periodUnit($period);
            $cursor = $billingPeriod->nominalRange->from;
            while ($cursor < $billingPeriod->nominalRange->to) {
                $next = $this->billingCycleResolver->advance($cursor, 1, $unit);
                if ($next > $billingPeriod->nominalRange->to) {
                    $next = $billingPeriod->nominalRange->to;
                }
                $bucket = new TimeRange($cursor, $next);
                $overlap = $bucket->intersection($effective);
                if ($overlap !== null) {
                    $units = $prorate ? $this->fraction($overlap, $bucket) : '1';
                    $totalUnits = $this->math->add($totalUnits, $units);
                }
                $cursor = $next;
            }
        }

        return new PeriodicChargeCalculation(
            $totalUnits,
            $this->math->multiply($totalUnits, $rate),
        );
    }

    private function fraction(TimeRange $part, TimeRange $whole): string
    {
        $partSeconds = (string)($part->to->getTimestamp() - $part->from->getTimestamp());
        $wholeSeconds = (string)($whole->to->getTimestamp() - $whole->from->getTimestamp());
        return $this->math->divide($partSeconds, $wholeSeconds);
    }

    private function periodUnit(string $period): BillingCycleUnit
    {
        return match ($period) {
            'DAY' => BillingCycleUnit::DAY,
            'WEEK' => BillingCycleUnit::WEEK,
            'MONTH' => BillingCycleUnit::MONTH,
            'YEAR' => BillingCycleUnit::YEAR,
            default => throw new \LogicException("Unsupported periodic charge unit '$period'."),
        };
    }
}
