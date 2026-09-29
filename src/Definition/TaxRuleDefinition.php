<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

use Supla\EnergyCostCalculator\Model\CostComponentKind;

final readonly class TaxRuleDefinition
{
    /** @param list<CostComponentKind> $appliesToKinds */
    public function __construct(
        public string $id,
        public string $type,
        public array $appliesToKinds,
        public string $rate,
        public ?string $unit = null,
        public ?string $base = null,
    ) {
    }

    public function appliesTo(CostComponentKind $kind): bool
    {
        return in_array($kind, $this->appliesToKinds, true);
    }
}
