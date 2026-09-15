<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

final readonly class BillingPeriodDefinition
{
    /** @param list<ComponentDefinition> $components */
    public function __construct(
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo,
        public array $components,
    ) {
    }

    public function contains(\DateTimeImmutable $timestamp): bool
    {
        return ($this->validFrom === null || $timestamp >= $this->validFrom)
            && ($this->validTo === null || $timestamp < $this->validTo);
    }
}
