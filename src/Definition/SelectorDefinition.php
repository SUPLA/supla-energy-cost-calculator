<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

final readonly class SelectorDefinition
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public string $type,
        public array $config = [],
    ) {
    }
}
