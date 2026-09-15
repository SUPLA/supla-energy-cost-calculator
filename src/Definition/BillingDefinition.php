<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

use Supla\EnergyCostCalculator\Model\ReferenceDataId;
use Supla\EnergyCostCalculator\Model\TimeRange;

final readonly class BillingDefinition
{
    /** @param list<BillingPeriodDefinition> $periods */
    public function __construct(
        public int $version,
        public string $currency,
        public string $timezone,
        public array $periods,
    ) {
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
