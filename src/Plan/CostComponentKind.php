<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

enum CostComponentKind: string
{
    case ENERGY_PURCHASE = 'ENERGY_PURCHASE';
    case DISTRIBUTION_VARIABLE = 'DISTRIBUTION_VARIABLE';
    case DISTRIBUTION_FIXED = 'DISTRIBUTION_FIXED';
    case SUPPLIER_FIXED = 'SUPPLIER_FIXED';

    public function category(): string
    {
        return match ($this) {
            self::ENERGY_PURCHASE => 'ENERGY',
            self::DISTRIBUTION_VARIABLE, self::DISTRIBUTION_FIXED => 'NETWORK',
            self::SUPPLIER_FIXED => 'SERVICE',
        };
    }

    public function isPeriodic(): bool
    {
        return $this === self::DISTRIBUTION_FIXED || $this === self::SUPPLIER_FIXED;
    }

    public function componentId(): string
    {
        return match ($this) {
            self::ENERGY_PURCHASE => 'energy-purchase',
            self::DISTRIBUTION_VARIABLE => 'distribution-variable',
            self::DISTRIBUTION_FIXED => 'distribution-fixed',
            self::SUPPLIER_FIXED => 'supplier-fixed',
        };
    }
}
