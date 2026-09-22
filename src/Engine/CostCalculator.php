<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Contract\EnergyDeltaSource;
use Supla\EnergyCostCalculator\Contract\ReferenceDataSource;
use Supla\EnergyCostCalculator\Definition\BillingDefinition;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Definition\BillingPeriodDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Exception\IntervalCrossesBillingPeriodException;
use Supla\EnergyCostCalculator\Exception\MissingBillingPeriodException;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Math\NativeDecimalMath;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;
use Supla\EnergyCostCalculator\Strategy\Quantity\DefaultQuantityResolver;
use Supla\EnergyCostCalculator\Strategy\Quantity\DefaultQuantityWindowResolver;
use Supla\EnergyCostCalculator\Strategy\Quantity\QuantityResolver;
use Supla\EnergyCostCalculator\Strategy\Quantity\QuantityWindowResolver;
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
        private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver(),
        private readonly QuantityWindowResolver $quantityWindowResolver = new DefaultQuantityWindowResolver(),
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

        $billingPeriods = $this->billingCycleResolver->periodsOverlappingTimeline(
            $range,
            $definition->billingCycles,
            $definition->timezone,
        );
        $this->assertBillingPeriodCoverage($range, $billingPeriods);
        $coversWholeBillingPeriods = $this->billingCycleResolver->rangeCoversWholeResolvedPeriods($range, $billingPeriods);

        $usageBasedTotal = '0';
        $usageBasedByComponent = [];
        $usageBasedByZone = [];
        $usage = [];
        $intervals = [];
        $charges = [];
        $processed = 0;
        $requiresCompleteDeltaCoverage = $this->definitionUsesTemporalNetting($definition, $range);
        $expectedDeltaFrom = $range->from;

        /** @var array<string, array<string, mixed>> $billingSummaryState */
        $billingSummaryState = [];
        foreach ($billingPeriods as $billingPeriod) {
            $billingSummaryState[$this->billingPeriodKey($billingPeriod)] = [
                'period' => $billingPeriod,
                'usage' => [],
                'usageBasedTotal' => '0',
                'usageBasedByComponent' => [],
                'usageBasedByZone' => [],
            ];
        }

        /** @var array<string, array<string, mixed>> $activeNettingWindows */
        $activeNettingWindows = [];

        $finalizeNettingWindow = function (array $window) use (
            &$usageBasedTotal,
            &$usageBasedByComponent,
            &$usageBasedByZone,
            &$billingSummaryState,
            &$charges,
            $billingPeriods,
            $options,
        ): void {
            /** @var list<EnergyDelta> $deltas */
            $deltas = $window['deltas'];
            $first = $deltas[0] ?? null;
            $last = $deltas[array_key_last($deltas)] ?? null;
            if (!$first instanceof EnergyDelta || !$last instanceof EnergyDelta
                || $first->from != $window['from'] || $last->to != $window['to']) {
                throw new CalculationException(sprintf(
                    "Component '%s' requires a complete netting window %s..%s.",
                    $window['component']->id,
                    $window['from']->format(DATE_ATOM),
                    $window['to']->format(DATE_ATOM),
                ));
            }

            $windowRange = new TimeRange($window['from'], $window['to']);
            $billingPeriod = $this->billingCycleResolver->periodContainingRange($windowRange, $billingPeriods);
            if ($billingPeriod === null) {
                throw new CalculationException(sprintf(
                    "Netting window %s..%s for component '%s' crosses a billing-period boundary.",
                    $window['from']->format(DATE_ATOM),
                    $window['to']->format(DATE_ATOM),
                    $window['component']->id,
                ));
            }

            $resolution = $this->quantityWindowResolver->resolve(
                $deltas,
                $window['component']->quantity,
                $this->math,
            );
            $cost = $this->math->multiply($resolution->value, $window['rate']);

            $componentId = $window['component']->id;
            $usageBasedTotal = $this->math->add($usageBasedTotal, $cost);
            $this->addAmount($usageBasedByComponent, $componentId, $cost);
            if ($window['selection'] !== null) {
                $this->addAmount($usageBasedByZone, (string)$window['selection'], $cost);
            }

            $summaryKey = $this->billingPeriodKey($billingPeriod);
            $summary = &$billingSummaryState[$summaryKey];
            $summary['usageBasedTotal'] = $this->math->add($summary['usageBasedTotal'], $cost);
            $this->addAmount($summary['usageBasedByComponent'], $componentId, $cost);
            if ($window['selection'] !== null) {
                $this->addAmount($summary['usageBasedByZone'], (string)$window['selection'], $cost);
            }
            unset($summary);

            if ($options->includeIntervals) {
                $charges[] = [
                    'componentId' => $componentId,
                    'category' => $window['component']->category,
                    'from' => $window['from']->format(DATE_ATOM),
                    'to' => $window['to']->format(DATE_ATOM),
                    'quantity' => [
                        'type' => $window['component']->quantity->type->value,
                        'strategy' => $window['component']->quantity->strategy?->value,
                        'periodInMinutes' => $window['component']->quantity->periodInMinutes,
                        'import' => $resolution->sourceQuantities[QuantityType::ACTIVE_ENERGY_IMPORT->value] ?? '0',
                        'export' => $resolution->sourceQuantities[QuantityType::ACTIVE_ENERGY_EXPORT->value] ?? '0',
                        'value' => $resolution->value,
                    ],
                    'selection' => $window['selection'],
                    'rate' => $window['rate'],
                    'cost' => $cost,
                ];
            }
        };

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
            if ($requiresCompleteDeltaCoverage && $delta->from != $expectedDeltaFrom) {
                throw new CalculationException(sprintf(
                    'Meter deltas contain a gap at %s; complete coverage is required for temporal netting.',
                    $expectedDeltaFrom->format(DATE_ATOM),
                ));
            }
            $expectedDeltaFrom = $delta->to;

            foreach ($activeNettingWindows as $key => $window) {
                if ($delta->from >= $window['to']) {
                    $finalizeNettingWindow($window);
                    unset($activeNettingWindows[$key]);
                }
            }

            $definitionPeriod = $definition->periodAt($delta->from)
                ?? throw new MissingBillingPeriodException('No billing definition period at ' . $delta->from->format(DATE_ATOM));
            $this->assertDeltaInsidePeriod($delta, $definitionPeriod);

            $billingPeriod = $this->billingCycleResolver->periodContainingRange($delta->range(), $billingPeriods);
            if ($billingPeriod === null) {
                throw new IntervalCrossesBillingPeriodException(sprintf(
                    'Delta %s..%s crosses a billing-cycle boundary.',
                    $delta->from->format(DATE_ATOM),
                    $delta->to->format(DATE_ATOM),
                ));
            }
            $summaryKey = $this->billingPeriodKey($billingPeriod);

            foreach ($delta->quantities as $type => $value) {
                $usage[$type] = $this->math->add($usage[$type] ?? '0', (string)$value);
                $billingSummaryState[$summaryKey]['usage'][$type] = $this->math->add(
                    $billingSummaryState[$summaryKey]['usage'][$type] ?? '0',
                    (string)$value,
                );
            }

            $intervalComponents = [];
            $intervalTotal = '0';
            $intervalByComponent = [];
            $intervalByZone = [];
            foreach ($definitionPeriod->components as $component) {
                if ($component->isPeriodic()) {
                    continue;
                }

                if ($component->quantity->usesTemporalNetting()) {
                    $window = $this->nettingWindow(
                        $delta->from,
                        $component->quantity->periodInMinutes ?? 0,
                        $definition->timezone,
                    );
                    if ($delta->to > $window->to) {
                        throw new CalculationException(sprintf(
                            "Delta %s..%s crosses netting window boundary %s for component '%s'.",
                            $delta->from->format(DATE_ATOM),
                            $delta->to->format(DATE_ATOM),
                            $window->to->format(DATE_ATOM),
                            $component->id,
                        ));
                    }
                    if ($this->billingCycleResolver->periodContainingRange($window, $billingPeriods) === null) {
                        throw new CalculationException(sprintf(
                            "Netting window %s..%s for component '%s' crosses a billing-period boundary.",
                            $window->from->format(DATE_ATOM),
                            $window->to->format(DATE_ATOM),
                            $component->id,
                        ));
                    }

                    $selection = $this->selectorResolver->resolve($delta, $component->selector, $references);
                    $rate = $this->rateResolver->resolve($delta, $component->rate, $selection, $references, $this->math);
                    $key = $component->id;
                    if (!isset($activeNettingWindows[$key])) {
                        $activeNettingWindows[$key] = [
                            'component' => $component,
                            'componentObjectId' => spl_object_id($component),
                            'from' => $window->from,
                            'to' => $window->to,
                            'deltas' => [],
                            'lastTo' => null,
                            'selection' => $selection,
                            'rate' => $rate,
                        ];
                    } else {
                        $active = $activeNettingWindows[$key];
                        if ($active['from'] != $window->from || $active['to'] != $window->to) {
                            throw new CalculationException(sprintf(
                                "Component '%s' has overlapping or non-contiguous netting windows.",
                                $component->id,
                            ));
                        }
                        if ($active['componentObjectId'] !== spl_object_id($component)) {
                            throw new CalculationException(sprintf(
                                "Component '%s' changes definition inside netting window %s..%s.",
                                $component->id,
                                $window->from->format(DATE_ATOM),
                                $window->to->format(DATE_ATOM),
                            ));
                        }
                        if ($active['selection'] !== $selection) {
                            throw new CalculationException(sprintf(
                                "Component '%s' changes selector result inside netting window %s..%s.",
                                $component->id,
                                $window->from->format(DATE_ATOM),
                                $window->to->format(DATE_ATOM),
                            ));
                        }
                        if ($active['rate'] !== $rate) {
                            throw new CalculationException(sprintf(
                                "Component '%s' changes rate inside netting window %s..%s.",
                                $component->id,
                                $window->from->format(DATE_ATOM),
                                $window->to->format(DATE_ATOM),
                            ));
                        }
                    }

                    $lastTo = $activeNettingWindows[$key]['lastTo'];
                    if ($lastTo instanceof \DateTimeImmutable && $lastTo != $delta->from) {
                        throw new CalculationException(sprintf(
                            "Meter deltas contain a gap inside netting window %s..%s for component '%s'.",
                            $window->from->format(DATE_ATOM),
                            $window->to->format(DATE_ATOM),
                            $component->id,
                        ));
                    }
                    $activeNettingWindows[$key]['deltas'][] = $delta;
                    $activeNettingWindows[$key]['lastTo'] = $delta->to;
                    continue;
                }

                $quantity = $this->quantityResolver->resolve($delta, $component->quantity);
                $selection = $this->selectorResolver->resolve($delta, $component->selector, $references);
                $rate = $this->rateResolver->resolve($delta, $component->rate, $selection, $references, $this->math);
                $cost = $this->math->multiply($quantity, $rate);

                $usageBasedTotal = $this->math->add($usageBasedTotal, $cost);
                $this->addAmount($usageBasedByComponent, $component->id, $cost);
                if ($selection !== null) {
                    $this->addAmount($usageBasedByZone, $selection, $cost);
                }

                $billingSummaryState[$summaryKey]['usageBasedTotal'] = $this->math->add(
                    $billingSummaryState[$summaryKey]['usageBasedTotal'],
                    $cost,
                );
                $this->addAmount($billingSummaryState[$summaryKey]['usageBasedByComponent'], $component->id, $cost);
                if ($selection !== null) {
                    $this->addAmount($billingSummaryState[$summaryKey]['usageBasedByZone'], $selection, $cost);
                }

                $intervalTotal = $this->math->add($intervalTotal, $cost);
                $this->addAmount($intervalByComponent, $component->id, $cost);
                if ($selection !== null) {
                    $this->addAmount($intervalByZone, $selection, $cost);
                }

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
                    $charges[] = [
                        'componentId' => $component->id,
                        'category' => $component->category,
                        'from' => $delta->from->format(DATE_ATOM),
                        'to' => $delta->to->format(DATE_ATOM),
                        'quantity' => [
                            'type' => $component->quantity->type->value,
                            'value' => $quantity,
                        ],
                        'selection' => $selection,
                        'rate' => $rate,
                        'cost' => $cost,
                    ];
                }
            }

            if ($options->includeIntervals) {
                $intervalUsage = $delta->quantities;
                ksort($intervalUsage);
                ksort($intervalByComponent);
                ksort($intervalByZone);
                $intervals[] = [
                    'from' => $delta->from->format(DATE_ATOM),
                    'to' => $delta->to->format(DATE_ATOM),
                    'usage' => $intervalUsage,
                    'costs' => [
                        'total' => $intervalTotal,
                        'byComponent' => $intervalByComponent,
                        'byZone' => $intervalByZone,
                    ],
                    'components' => $intervalComponents,
                ];
            }
            $processed++;
        }

        if ($requiresCompleteDeltaCoverage && $expectedDeltaFrom != $range->to) {
            throw new CalculationException(sprintf(
                'Meter deltas end at %s; complete coverage through %s is required for temporal netting.',
                $expectedDeltaFrom->format(DATE_ATOM),
                $range->to->format(DATE_ATOM),
            ));
        }

        foreach ($activeNettingWindows as $window) {
            $finalizeNettingWindow($window);
        }

        ksort($usage);
        ksort($usageBasedByComponent);
        ksort($usageBasedByZone);

        [$periodicCharges, $periodicTotal, $periodicByComponent] = $this->periodicCharges(
            $definition,
            $range,
            $billingPeriods,
            $coversWholeBillingPeriods,
        );

        $hasPeriodicCharges = $periodicCharges !== [];
        $total = match (true) {
            !$hasPeriodicCharges => $usageBasedTotal,
            $coversWholeBillingPeriods => $this->math->add($usageBasedTotal, $periodicTotal ?? '0'),
            default => null,
        };

        $billingContextPeriods = [];
        foreach ($billingPeriods as $billingPeriod) {
            $fullyCovered = $this->rangeContains($range, $billingPeriod->range);
            $billingContextPeriods[] = [
                'from' => $billingPeriod->range->from->format(DATE_ATOM),
                'to' => $billingPeriod->range->to->format(DATE_ATOM),
                'fullyCovered' => $fullyCovered,
                'transitional' => $billingPeriod->isTransitional(),
            ];
        }

        $billingPeriodSummaries = $this->billingPeriodSummaries(
            $definition,
            $range,
            $billingPeriods,
            $billingSummaryState,
        );

        $legacyBillingCycle = count($definition->billingCycles) === 1
            && $definition->billingCycles[0]->validFrom === null
            && $definition->billingCycles[0]->validTo === null
                ? $definition->billingCycle
                : null;

        return new CalculationResult(
            $definition->currency,
            $range,
            $legacyBillingCycle,
            $definition->billingCycles,
            $usage,
            $usageBasedTotal,
            $usageBasedByComponent,
            $usageBasedByZone,
            $periodicTotal,
            $periodicByComponent,
            $total,
            $periodicCharges,
            [
                'requestedRangeCoversWholePeriods' => $coversWholeBillingPeriods,
                'periods' => $billingContextPeriods,
            ],
            $billingPeriodSummaries,
            $processed,
            $intervals,
            $charges,
        );
    }

    /**
     * @param list<ResolvedBillingPeriod> $billingPeriods
     * @return array{0: list<array<string, mixed>>, 1: ?string, 2: array<string, string>}
     */
    private function periodicCharges(
        BillingDefinition $definition,
        TimeRange $range,
        array $billingPeriods,
        bool $calculate,
    ): array {
        $periodicCalculator = new PeriodicChargeCalculator($this->math, $this->billingCycleResolver);
        $charges = [];
        $total = $calculate ? '0' : null;
        $byComponent = [];

        foreach ($definition->periods as $period) {
            $periodRange = $this->periodRange($period, $range);
            if ($periodRange === null) {
                continue;
            }
            foreach ($period->components as $component) {
                if (!$component->isPeriodic()) {
                    continue;
                }

                $calculated = null;
                if ($calculate) {
                    $calculation = $periodicCalculator->calculate($component, $periodRange, $billingPeriods);
                    $calculated = [
                        'units' => $calculation->units,
                        'amount' => $calculation->amount,
                    ];
                    $total = $this->math->add($total ?? '0', $calculation->amount);
                    $this->addAmount($byComponent, $component->id, $calculation->amount);
                }

                $charges[] = [
                    'id' => $component->id,
                    'category' => $component->category,
                    'appliesFrom' => $periodRange->from->format(DATE_ATOM),
                    'appliesTo' => $periodRange->to->format(DATE_ATOM),
                    'definition' => [
                        'period' => (string)$component->quantity->options['period'],
                        'prorate' => (bool)($component->quantity->options['prorate'] ?? false),
                        'rate' => (string)$component->rate->config['value'],
                        'unit' => $component->rate->config['unit'] ?? null,
                    ],
                    'calculated' => $calculated,
                ];
            }
        }

        if ($charges === []) {
            return [[], '0', []];
        }
        ksort($byComponent);
        return [$charges, $total, $byComponent];
    }

    /**
     * @param list<ResolvedBillingPeriod> $billingPeriods
     * @param array<string, array<string, mixed>> $summaryState
     * @return list<array<string, mixed>>
     */
    private function billingPeriodSummaries(
        BillingDefinition $definition,
        TimeRange $requestedRange,
        array $billingPeriods,
        array $summaryState,
    ): array {
        $summaries = [];
        foreach ($billingPeriods as $billingPeriod) {
            $key = $this->billingPeriodKey($billingPeriod);
            $state = $summaryState[$key];
            $fullyCovered = $this->rangeContains($requestedRange, $billingPeriod->range);

            [$periodicCharges, $periodicTotal, $periodicByComponent] = $this->periodicCharges(
                $definition,
                $billingPeriod->range,
                [$billingPeriod],
                $fullyCovered,
            );
            $hasPeriodicCharges = $periodicCharges !== [];
            $periodTotal = match (true) {
                !$hasPeriodicCharges => $state['usageBasedTotal'],
                $fullyCovered => $this->math->add($state['usageBasedTotal'], $periodicTotal ?? '0'),
                default => null,
            };

            $periodUsage = $state['usage'];
            $byComponent = $state['usageBasedByComponent'];
            $byZone = $state['usageBasedByZone'];
            ksort($periodUsage);
            ksort($byComponent);
            ksort($byZone);
            ksort($periodicByComponent);

            $summaries[] = [
                'from' => $billingPeriod->range->from->format(DATE_ATOM),
                'to' => $billingPeriod->range->to->format(DATE_ATOM),
                'fullyCovered' => $fullyCovered,
                'transitional' => $billingPeriod->isTransitional(),
                'billingCycle' => $billingPeriod->definition->cycle->jsonSerialize(),
                'usage' => $periodUsage,
                'costs' => [
                    'usageBased' => [
                        'total' => $state['usageBasedTotal'],
                        'byComponent' => $byComponent,
                        'byZone' => $byZone,
                    ],
                    'periodic' => [
                        'total' => $periodicTotal,
                        'byComponent' => $periodicByComponent,
                    ],
                    'total' => $periodTotal,
                ],
            ];
        }
        return $summaries;
    }

    /** @param list<ResolvedBillingPeriod> $billingPeriods */
    private function assertBillingPeriodCoverage(TimeRange $range, array $billingPeriods): void
    {
        if ($billingPeriods === []) {
            throw new CalculationException('No billing cycle is defined for the requested range.');
        }

        $expected = $range->from;
        foreach ($billingPeriods as $billingPeriod) {
            $overlap = $billingPeriod->range->intersection($range);
            if ($overlap === null) {
                continue;
            }
            if ($overlap->from->getTimestamp() !== $expected->getTimestamp()) {
                throw new CalculationException(sprintf(
                    'Billing-cycle history contains a gap at %s.',
                    $expected->format(DATE_ATOM),
                ));
            }
            $expected = $overlap->to;
        }

        if ($expected->getTimestamp() !== $range->to->getTimestamp()) {
            throw new CalculationException(sprintf(
                'Billing-cycle history does not cover the requested range through %s.',
                $range->to->format(DATE_ATOM),
            ));
        }
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

    private function definitionUsesTemporalNetting(BillingDefinition $definition, TimeRange $range): bool
    {
        foreach ($definition->periods as $period) {
            if ($this->periodRange($period, $range) === null) {
                continue;
            }
            foreach ($period->components as $component) {
                if (!$component->isPeriodic() && $component->quantity->usesTemporalNetting()) {
                    return true;
                }
            }
        }
        return false;
    }

    private function nettingWindow(\DateTimeImmutable $timestamp, int $periodInMinutes, string $timezone): TimeRange
    {
        if ($periodInMinutes < 1) {
            throw new CalculationException('Netting periodInMinutes must be positive.');
        }

        $tz = new \DateTimeZone($timezone);
        $periodSeconds = $periodInMinutes * 60;
        $offset = $tz->getOffset($timestamp);
        $localEpoch = $timestamp->getTimestamp() + $offset;
        $bucketLocalEpoch = intdiv($localEpoch, $periodSeconds) * $periodSeconds;
        $fromTimestamp = $bucketLocalEpoch - $offset;

        $from = (new \DateTimeImmutable('@' . $fromTimestamp))->setTimezone($tz);
        $to = (new \DateTimeImmutable('@' . ($fromTimestamp + $periodSeconds)))->setTimezone($tz);
        return new TimeRange($from, $to);
    }

    /** @param array<string, string> $target */
    private function addAmount(array &$target, string $key, string $amount): void
    {
        $target[$key] = $this->math->add($target[$key] ?? '0', $amount);
    }

    private function billingPeriodKey(ResolvedBillingPeriod $period): string
    {
        return $period->range->from->getTimestamp() . ':' . $period->range->to->getTimestamp();
    }

    private function rangeContains(TimeRange $outer, TimeRange $inner): bool
    {
        return $outer->from <= $inner->from && $outer->to >= $inner->to;
    }
}
