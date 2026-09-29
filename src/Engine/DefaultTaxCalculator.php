<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\TaxRuleDefinition;
use Supla\EnergyCostCalculator\Definition\TaxTreatment;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Math\NativeDecimalMath;
use Supla\EnergyCostCalculator\Model\CostComponentKind;

final class DefaultTaxCalculator implements TaxCalculator
{
    public function __construct(private readonly DecimalMath $math = new NativeDecimalMath())
    {
    }

    public function calculate(string $sourceAmount, string $quantity, CostComponentKind $kind, TaxTreatment $treatment, array $rules): TaxCalculation
    {
        $applicable = array_values(array_filter(
            $rules,
            static fn(TaxRuleDefinition $rule): bool => $rule->appliesTo($kind),
        ));
        $applicableIds = array_map(static fn(TaxRuleDefinition $rule): string => $rule->id, $applicable);
        if ($treatment->included !== array_slice($applicableIds, 0, count($treatment->included))) {
            throw new CalculationException("Included taxes for component kind '{$kind->value}' must be an ordered prefix of applicable tax rules.");
        }

        $net = $sourceAmount;
        for ($index = count($treatment->included) - 1; $index >= 0; $index--) {
            $rule = $applicable[$index];
            $net = match ($rule->type) {
                'PER_QUANTITY' => $this->subtract($net, $this->perQuantityAmount($rule, $quantity)),
                'PERCENTAGE' => $this->math->divide($net, $this->math->add('1', $rule->rate)),
                default => throw new CalculationException("Unsupported tax rule type '$rule->type'."),
            };
        }

        $subtotal = $net;
        $taxes = [];
        foreach ($applicable as $index => $rule) {
            $taxableBase = $rule->type === 'PERCENTAGE' ? $subtotal : null;
            $amount = $rule->type === 'PER_QUANTITY'
                ? $this->perQuantityAmount($rule, $quantity)
                : $this->math->multiply($subtotal, $rule->rate);
            $taxes[$rule->id] = [
                'type' => $rule->type,
                'rate' => $rule->rate,
                'unit' => $rule->unit,
                'taxableBase' => $taxableBase,
                'amount' => $amount,
                'includedInSourceAmount' => $index < count($treatment->included),
            ];
            $subtotal = $this->math->add($subtotal, $amount);
        }

        return new TaxCalculation($net, $taxes, $this->subtract($subtotal, $net), $subtotal);
    }

    private function perQuantityAmount(TaxRuleDefinition $rule, string $quantity): string
    {
        if ($rule->unit === null || !str_ends_with($rule->unit, '/kWh')) {
            throw new CalculationException("PER_QUANTITY tax rule '$rule->id' requires a supported quantity unit.");
        }
        return $this->math->multiply($quantity, $rule->rate);
    }

    private function subtract(string $left, string $right): string
    {
        return $this->math->add($left, '-' . $right);
    }
}
