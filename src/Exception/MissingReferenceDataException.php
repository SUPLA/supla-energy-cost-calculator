<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Exception;

final class MissingReferenceDataException extends CalculationException {
    public function __construct(
        string $message,
        public readonly ?string $referenceDataId = null,
        public readonly ?\DateTimeImmutable $timestamp = null,
    ) {
        parent::__construct($message);
    }
}
