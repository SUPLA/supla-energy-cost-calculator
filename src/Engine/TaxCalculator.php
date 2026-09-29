<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\TaxRuleDefinition;
use Supla\EnergyCostCalculator\Definition\TaxTreatment;

interface TaxCalculator
{
    /** @param list<TaxRuleDefinition> $rules */
    public function calculate(string $sourceAmount, string $quantity, string $kind, TaxTreatment $treatment, array $rules): TaxCalculation;
}
