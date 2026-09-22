<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

use Supla\EnergyCostCalculator\Model\ReferenceDataId;
use Supla\EnergyCostCalculator\Model\TimeRange;

final readonly class BillingDefinition
{
    /** Backwards-compatible alias for the first billing-cycle definition. */
    public BillingCycleDefinition $billingCycle;

    /** @var list<BillingCyclePeriodDefinition> */
    public array $billingCycles;

    /** @var list<BillingPeriodDefinition> */
    public array $periods;

    /**
     * @param BillingCycleDefinition|list<BillingCyclePeriodDefinition> $billingCycleOrCycles
     * @param list<BillingPeriodDefinition> $periods
     */
    public function __construct(
        public int $version,
        public string $currency,
        public string $timezone,
        BillingCycleDefinition|array $billingCycleOrCycles,
        array $periods,
    ) {
        $billingCycles = $billingCycleOrCycles instanceof BillingCycleDefinition
            ? [new BillingCyclePeriodDefinition(null, null, $billingCycleOrCycles)]
            : $billingCycleOrCycles;
        if ($billingCycles === []) {
            throw new \InvalidArgumentException('BillingDefinition requires at least one billing cycle period.');
        }
        foreach ($billingCycles as $billingCyclePeriod) {
            if (!$billingCyclePeriod instanceof BillingCyclePeriodDefinition) {
                throw new \InvalidArgumentException('BillingDefinition billingCycles must contain BillingCyclePeriodDefinition objects.');
            }
        }
        foreach ($periods as $period) {
            if (!$period instanceof BillingPeriodDefinition) {
                throw new \InvalidArgumentException('BillingDefinition periods must contain BillingPeriodDefinition objects.');
            }
        }

        $this->billingCycles = array_values($billingCycles);
        $this->billingCycle = $this->billingCycles[0]->cycle;
        $this->periods = array_values($periods);
    }

    public function periodAt(\DateTimeImmutable $timestamp): ?BillingPeriodDefinition
    {
        foreach ($this->periods as $period) {
            if ($period->contains($timestamp)) {
                return $period;
            }
        }
        return null;
    }

    public function billingCycleAt(\DateTimeImmutable $timestamp): ?BillingCyclePeriodDefinition
    {
        foreach ($this->billingCycles as $period) {
            if ($period->contains($timestamp)) {
                return $period;
            }
        }
        return null;
    }

    /** @return list<ReferenceDataId> */
    public function referenceDataIds(TimeRange $range): array
    {
        $ids = [];
        foreach ($this->periods as $period) {
            if (!$this->periodOverlapsRange($period, $range)) {
                continue;
            }
            foreach ($period->components as $component) {
                if ($component->selector->type === 'REFERENCE') {
                    $id = (string)($component->selector->config['source'] ?? '');
                    if ($id !== '') {
                        $ids[$id] = new ReferenceDataId($id);
                    }
                }
                if ($component->rate->type === 'REFERENCE') {
                    $id = (string)($component->rate->config['source'] ?? '');
                    if ($id !== '') {
                        $ids[$id] = new ReferenceDataId($id);
                    }
                }
            }
        }
        return array_values($ids);
    }

    private function periodOverlapsRange(BillingPeriodDefinition $period, TimeRange $range): bool
    {
        $fromOk = $period->validTo === null || $period->validTo > $range->from;
        $toOk = $period->validFrom === null || $period->validFrom < $range->to;
        return $fromOk && $toOk;
    }
}
