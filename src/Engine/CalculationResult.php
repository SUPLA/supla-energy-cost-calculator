<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\BillingCycleDefinition;
use Supla\EnergyCostCalculator\Model\TimeRange;

final readonly class CalculationResult implements \JsonSerializable
{
    /**
     * @param array<string, string> $usage
     * @param array<string, string> $usageBasedByComponent
     * @param array<string, string> $periodicByComponent
     * @param list<array<string, mixed>> $periodicCharges
     * @param array<string, mixed> $billingContext
     * @param list<array<string, mixed>> $intervals
     * @param list<array<string, mixed>> $charges
     */
    public function __construct(
        public string $currency,
        public TimeRange $range,
        public BillingCycleDefinition $billingCycle,
        public array $usage,
        public string $usageBasedTotal,
        public array $usageBasedByComponent,
        public ?string $periodicTotal,
        public array $periodicByComponent,
        public ?string $total,
        public array $periodicCharges,
        public array $billingContext,
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
            'billingCycle' => $this->billingCycle->jsonSerialize(),
            'billingContext' => $this->billingContext,
            'usage' => $this->usage,
            'costs' => [
                'usageBased' => [
                    'total' => $this->usageBasedTotal,
                    'byComponent' => $this->usageBasedByComponent,
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
