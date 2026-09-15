<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Support;

use Supla\EnergyCostCalculator\Contract\ReferenceDataSource;
use Supla\EnergyCostCalculator\Model\ReferenceDataId;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class InMemoryReferenceDataSource implements ReferenceDataSource
{
    /** @param array<string, array> $series */
    public function __construct(private readonly array $series = [])
    {
    }

    public function get(ReferenceDataId $id, TimeRange $range): iterable
    {
        foreach ($this->series[$id->value] ?? [] as $interval) {
            yield $interval;
        }
    }
}
