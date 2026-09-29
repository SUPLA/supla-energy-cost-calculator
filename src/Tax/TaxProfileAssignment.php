<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tax;

final readonly class TaxProfileAssignment
{
    public function __construct(
        public TaxContext $taxContext,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo,
        public string $profileId,
    ) {
    }
}
