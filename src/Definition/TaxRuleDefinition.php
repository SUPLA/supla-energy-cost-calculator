<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

final readonly class TaxRuleDefinition
{
    /** @param list<string> $appliesToKinds */
    public function __construct(
        public string $id,
        public string $type,
        public array $appliesToKinds,
        public string $rate,
        public ?string $unit = null,
        public ?string $base = null,
    ) {
    }

    public function appliesTo(string $kind): bool
    {
        return in_array($kind, $this->appliesToKinds, true);
    }
}
