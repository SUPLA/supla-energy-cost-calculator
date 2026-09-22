<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\BillingCycleDefinition;
use Supla\EnergyCostCalculator\Definition\BillingCyclePeriodDefinition;
use Supla\EnergyCostCalculator\Model\TimeRange;

final readonly class CalculationResult implements \JsonSerializable
{
    /**
     * @param list<BillingCyclePeriodDefinition> $billingCycles
     * @param array<string, string> $usage
     * @param array<string, string> $usageBasedByComponent
     * @param array<string, string> $usageBasedByZone
     * @param array<string, string> $periodicByComponent
     * @param list<array<string, mixed>> $periodicCharges
     * @param array<string, mixed> $billingContext
     * @param list<array<string, mixed>> $billingPeriods
     * @param list<array<string, mixed>> $intervals
     * @param list<array<string, mixed>> $charges
     */
    public function __construct(
        public string $currency,
        public TimeRange $range,
        public ?BillingCycleDefinition $billingCycle,
        public array $billingCycles,
        public array $usage,
        public string $usageBasedTotal,
        public array $usageBasedByComponent,
        public array $usageBasedByZone,
        public ?string $periodicTotal,
        public array $periodicByComponent,
        public ?string $total,
        public array $periodicCharges,
        public array $billingContext,
        public array $billingPeriods,
        public int $processedDeltaCount,
        public array $intervals = [],
        public array $charges = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'currency' => $this->currency,
            'range' => [
                'from' => $this->range->from->format(DATE_ATOM),
                'to' => $this->range->to->format(DATE_ATOM),
            ],
            'billingCycle' => $this->billingCycle?->jsonSerialize(),
            'billingCycles' => array_map(
                static fn(BillingCyclePeriodDefinition $period): array => $period->jsonSerialize(),
                $this->billingCycles,
            ),
            'billingContext' => $this->billingContext,
            'billingPeriods' => $this->billingPeriods,
            'usage' => $this->usage,
            'costs' => [
                'usageBased' => [
                    'total' => $this->usageBasedTotal,
                    'byComponent' => $this->usageBasedByComponent,
                    'byZone' => $this->usageBasedByZone,
                ],
                'periodic' => [
                    'total' => $this->periodicTotal,
                    'byComponent' => $this->periodicByComponent,
                ],
                'total' => $this->total,
            ],
            'periodicCharges' => $this->periodicCharges,
            'processedDeltaCount' => $this->processedDeltaCount,
            'intervals' => $this->intervals,
            'charges' => $this->charges,
        ];
    }
}
