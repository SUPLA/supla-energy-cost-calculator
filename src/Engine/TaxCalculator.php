<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\TaxRuleDefinition;
use Supla\EnergyCostCalculator\Definition\TaxTreatment;
use Supla\EnergyCostCalculator\Model\CostComponentKind;

interface TaxCalculator
{
    /** @param list<TaxRuleDefinition> $rules */
    public function calculate(string $sourceAmount, string $quantity, CostComponentKind $kind, TaxTreatment $treatment, array $rules): TaxCalculation;
}
