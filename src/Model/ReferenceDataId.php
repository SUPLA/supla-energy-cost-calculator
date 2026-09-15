<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Model;

use InvalidArgumentException;

final readonly class ReferenceDataId
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('ReferenceDataId cannot be empty.');
        }
    }
}
