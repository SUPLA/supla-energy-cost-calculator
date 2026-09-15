<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Model;

use InvalidArgumentException;

final readonly class TimeRange
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
        if ($from >= $to) {
            throw new InvalidArgumentException('TimeRange.from must be before TimeRange.to.');
        }
    }

    public function overlaps(self $other): bool
    {
        return $this->from < $other->to && $this->to > $other->from;
    }

    public function intersection(self $other): ?self
    {
        $from = $this->from > $other->from ? $this->from : $other->from;
        $to = $this->to < $other->to ? $this->to : $other->to;

        return $from < $to ? new self($from, $to) : null;
    }
}
