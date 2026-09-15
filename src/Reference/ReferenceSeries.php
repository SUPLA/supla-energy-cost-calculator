<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Reference;

use Supla\EnergyCostCalculator\Exception\MissingReferenceDataException;
use Supla\EnergyCostCalculator\Model\ReferenceDataId;
use Supla\EnergyCostCalculator\Model\ReferenceInterval;

final class ReferenceSeries
{
    /** @var list<ReferenceInterval> */
    private array $intervals;

    /** @param iterable<ReferenceInterval> $intervals */
    public function __construct(
        private readonly ReferenceDataId $id,
        iterable $intervals,
    ) {
        $this->intervals = is_array($intervals) ? array_values($intervals) : iterator_to_array($intervals, false);
        usort($this->intervals, static fn(ReferenceInterval $a, ReferenceInterval $b) => $a->from <=> $b->from);
    }

    public function valueAt(\DateTimeImmutable $timestamp): ReferenceInterval
    {
        $left = 0;
        $right = count($this->intervals) - 1;
        $match = null;

        while ($left <= $right) {
            $mid = intdiv($left + $right, 2);
            $candidate = $this->intervals[$mid];
            if ($candidate->from <= $timestamp) {
                $match = $candidate;
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        if ($match !== null && $timestamp < $match->to) {
            return $match;
        }

        throw new MissingReferenceDataException(sprintf(
            'No reference data for %s at %s.',
            $this->id->value,
            $timestamp->format(DATE_ATOM),
        ));
    }
}
