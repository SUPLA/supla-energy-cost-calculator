<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

final readonly class TaxRuleSetDefinition
{
    /** @param list<TaxRuleDefinition> $rules */
    public function __construct(
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo,
        public array $rules,
    ) {
    }

    public function contains(\DateTimeImmutable $timestamp): bool
    {
        return ($this->validFrom === null || $timestamp >= $this->validFrom)
            && ($this->validTo === null || $timestamp < $this->validTo);
    }
}
