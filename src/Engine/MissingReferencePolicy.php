<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

enum MissingReferencePolicy: string
{
    case STRICT = 'STRICT';
    case SKIP_AFFECTED = 'SKIP_AFFECTED';
}
