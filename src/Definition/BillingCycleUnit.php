<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

enum BillingCycleUnit: string
{
    case DAY = 'DAY';
    case WEEK = 'WEEK';
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
}
