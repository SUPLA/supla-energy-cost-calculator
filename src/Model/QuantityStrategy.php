<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Model;

enum QuantityStrategy: string
{
    case IMPORT_MINUS_EXPORT = 'IMPORT_MINUS_EXPORT';
    case IMPORT_MINUS_EXPORT_CAP_ZERO = 'IMPORT_MINUS_EXPORT_CAP_ZERO';
}
