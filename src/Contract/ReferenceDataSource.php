<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Contract;

use Supla\EnergyCostCalculator\Model\ReferenceDataId;
use Supla\EnergyCostCalculator\Model\ReferenceInterval;
use Supla\EnergyCostCalculator\Model\TimeRange;

interface ReferenceDataSource
{
    /**
     * Must return reference intervals overlapping the requested range.
     *
     * @return iterable<ReferenceInterval>
     */
    public function get(ReferenceDataId $id, TimeRange $range): iterable;
}
