<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Contract\EnergyDeltaSource;
use Supla\EnergyCostCalculator\Contract\ReferenceDataSource;
use Supla\EnergyCostCalculator\Definition\BillingDefinition;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Definition\BillingPeriodDefinition;
use Supla\EnergyCostCalculator\Exception\IntervalCrossesBillingPeriodException;
use Supla\EnergyCostCalculator\Exception\MissingBillingPeriodException;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Math\NativeDecimalMath;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;
use Supla\EnergyCostCalculator\Strategy\Quantity\DefaultQuantityResolver;
use Supla\EnergyCostCalculator\Strategy\Quantity\QuantityResolver;
use Supla\EnergyCostCalculator\Strategy\Rate\DefaultRateResolver;
use Supla\EnergyCostCalculator\Strategy\Rate\RateResolver;
use Supla\EnergyCostCalculator\Strategy\Selector\DefaultSelectorResolver;
use Supla\EnergyCostCalculator\Strategy\Selector\SelectorResolver;

final class CostCalculator
{
    public function __construct(
        private readonly EnergyDeltaSource $deltaSource,
        private readonly ReferenceDataSource $referenceDataSource,
        private readonly BillingDefinitionParser $definitionParser = new BillingDefinitionParser(),
        private readonly QuantityResolver $quantityResolver = new DefaultQuantityResolver(),
        private readonly SelectorResolver $selectorResolver = new DefaultSelectorResolver(),
        private readonly RateResolver $rateResolver = new DefaultRateResolver(),
        private readonly DecimalMath $math = new NativeDecimalMath(),
    ) {
    }

    public function calculate(
        string $meterId,
        TimeRange $range,
        string|array|BillingDefinition $definition,
        ?CalculationOptions $options = null,
    ): CalculationResult {
        $options ??= new CalculationOptions();
        $definition = $definition instanceof BillingDefinition ? $definition : $this->definitionParser->parse($definition);

        $references = new ReferenceDataCache($this->referenceDataSource, $range);
        $references->preload($definition->referenceDataIds($range));

        $total = '0';
        $byComponent = [];
        $intervals = [];
        $processed = 0;

        foreach ($this->deltaSource->getDeltas($meterId, $range) as $delta) {
            if (!$delta instanceof EnergyDelta) {
                throw new \UnexpectedValueException('EnergyDeltaSource must yield EnergyDelta objects.');
            }
            $intersection = $delta->range()->intersection($range);
            if ($intersection === null) {
                continue;
            }
            if ($intersection->from != $delta->from || $intersection->to != $delta->to) {
                throw new IntervalCrossesBillingPeriodException('Requested range cuts through a delta interval. Use boundaries aligned to meter intervals.');
            }

            $period = $definition->periodAt($delta->from)
                ?? throw new MissingBillingPeriodException('No billing definition period at ' . $delta->from->format(DATE_ATOM));
            $this->assertDeltaInsidePeriod($delta, $period);

            $intervalComponents = [];
            foreach ($period->components as $component) {
                if ($component->isPeriodic()) {
                    continue;
                }
                $quantity = $this->quantityResolver->resolve($delta, $component->quantity);
                $selection = $this->selectorResolver->resolve($delta, $component->selector, $references);
                $rate = $this->rateResolver->resolve($delta, $component->rate, $selection, $references, $this->math);
                $cost = $this->math->multiply($quantity, $rate);

                $total = $this->math->add($total, $cost);
                $byComponent[$component->id] = $this->math->add($byComponent[$component->id] ?? '0', $cost);

                if ($options->includeIntervals) {
                    $intervalComponents[] = [
                        'id' => $component->id,
                        'category' => $component->category,
                        'quantityType' => $component->quantity->type->value,
                        'quantity' => $quantity,
                        'selection' => $selection,
                        'rate' => $rate,
                        'cost' => $cost,
                    ];
                }
            }

            if ($options->includeIntervals) {
                $intervals[] = [
                    'from' => $delta->from->format(DATE_ATOM),
                    'to' => $delta->to->format(DATE_ATOM),
                    'components' => $intervalComponents,
                ];
            }
            $processed++;
        }

        $periodicCalculator = new PeriodicChargeCalculator($this->math);
        foreach ($definition->periods as $period) {
            $periodRange = $this->periodRange($period, $range);
            if ($periodRange === null) {
                continue;
            }
            foreach ($period->components as $component) {
                if (!$component->isPeriodic()) {
                    continue;
                }
                $cost = $periodicCalculator->cost($component, $periodRange, $definition->timezone);
                $total = $this->math->add($total, $cost);
                $byComponent[$component->id] = $this->math->add($byComponent[$component->id] ?? '0', $cost);
            }
        }

        ksort($byComponent);
        return new CalculationResult($definition->currency, $total, $byComponent, $processed, $intervals);
    }

    private function assertDeltaInsidePeriod(EnergyDelta $delta, BillingPeriodDefinition $period): void
    {
        if ($period->validTo !== null && $delta->to > $period->validTo) {
            throw new IntervalCrossesBillingPeriodException(sprintf(
                'Delta %s..%s crosses billing definition boundary %s.',
                $delta->from->format(DATE_ATOM),
                $delta->to->format(DATE_ATOM),
                $period->validTo->format(DATE_ATOM),
            ));
        }
    }

    private function periodRange(BillingPeriodDefinition $period, TimeRange $requested): ?TimeRange
    {
        $from = $period->validFrom !== null && $period->validFrom > $requested->from ? $period->validFrom : $requested->from;
        $to = $period->validTo !== null && $period->validTo < $requested->to ? $period->validTo : $requested->to;
        return $from < $to ? new TimeRange($from, $to) : null;
    }
}
