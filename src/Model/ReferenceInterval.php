<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Model;

use InvalidArgumentException;

final readonly class ReferenceInterval
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $value,
        public ?string $unit = null,
    ) {
        if ($from >= $to) {
            throw new InvalidArgumentException('ReferenceInterval.from must be before ReferenceInterval.to.');
        }
    }
}
