<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

final readonly class BillingCyclePeriodDefinition implements \JsonSerializable
{
    public function __construct(
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo,
        public BillingCycleDefinition $cycle,
    ) {
        if ($validFrom !== null && $validTo !== null && $validFrom >= $validTo) {
            throw new \InvalidArgumentException('Billing cycle period validFrom must be before validTo.');
        }
    }

    public function contains(\DateTimeImmutable $timestamp): bool
    {
        return ($this->validFrom === null || $timestamp >= $this->validFrom)
            && ($this->validTo === null || $timestamp < $this->validTo);
    }

    public function jsonSerialize(): array
    {
        return [
            'validFrom' => $this->validFrom?->format(DATE_ATOM),
            'validTo' => $this->validTo?->format(DATE_ATOM),
            ...$this->cycle->jsonSerialize(),
        ];
    }
}
