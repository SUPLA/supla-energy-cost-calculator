<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tax;

use Supla\EnergyCostCalculator\Definition\TaxRuleDefinition;

final readonly class TaxProfile
{
    /** @param list<TaxRuleDefinition> $rules @param array<string, mixed> $document */
    public function __construct(
        public int $version,
        public string $id,
        public string $label,
        public string $currency,
        public array $rules,
        public array $document = [],
    ) {
    }
}
