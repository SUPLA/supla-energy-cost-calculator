<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tax;

final readonly class TaxContext
{
    public function __construct(
        public string $jurisdiction,
        public string $customerClass,
    ) {
        if (trim($this->jurisdiction) === '' || trim($this->customerClass) === '') {
            throw new \InvalidArgumentException('Tax context jurisdiction and customerClass must be non-empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->jurisdiction === $other->jurisdiction
            && $this->customerClass === $other->customerClass;
    }

    /** @return array{jurisdiction: string, customerClass: string} */
    public function toArray(): array
    {
        return [
            'jurisdiction' => $this->jurisdiction,
            'customerClass' => $this->customerClass,
        ];
    }
}
