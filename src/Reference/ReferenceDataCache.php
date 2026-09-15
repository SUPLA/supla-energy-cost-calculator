<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Reference;

use Supla\EnergyCostCalculator\Contract\ReferenceDataSource;
use Supla\EnergyCostCalculator\Model\ReferenceDataId;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class ReferenceDataCache
{
    /** @var array<string, ReferenceSeries> */
    private array $series = [];

    public function __construct(
        private readonly ReferenceDataSource $source,
        private readonly TimeRange $range,
    ) {
    }

    /** @param iterable<ReferenceDataId> $ids */
    public function preload(iterable $ids): void
    {
        foreach ($ids as $id) {
            $this->series[$id->value] ??= new ReferenceSeries($id, $this->source->get($id, $this->range));
        }
    }

    public function series(string $id): ReferenceSeries
    {
        if (!isset($this->series[$id])) {
            $referenceId = new ReferenceDataId($id);
            $this->series[$id] = new ReferenceSeries($referenceId, $this->source->get($referenceId, $this->range));
        }
        return $this->series[$id];
    }
}
