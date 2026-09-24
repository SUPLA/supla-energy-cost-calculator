<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Model;

enum QuantityType: string
{
    case ACTIVE_ENERGY_IMPORT = 'ACTIVE_ENERGY_IMPORT';
    case ACTIVE_ENERGY_EXPORT = 'ACTIVE_ENERGY_EXPORT';
    case REACTIVE_ENERGY_IMPORT = 'REACTIVE_ENERGY_IMPORT';
    case REACTIVE_ENERGY_EXPORT = 'REACTIVE_ENERGY_EXPORT';
    case PERIOD = 'PERIOD';
}
