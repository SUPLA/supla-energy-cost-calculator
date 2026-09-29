<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

final readonly class TaxTreatment
{
    /** @param list<string> $included */
    public function __construct(public array $included)
    {
    }
}
