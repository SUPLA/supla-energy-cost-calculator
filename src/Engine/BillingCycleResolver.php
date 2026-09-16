<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\BillingCycleDefinition;
use Supla\EnergyCostCalculator\Definition\BillingCycleUnit;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class BillingCycleResolver
{
    public function periodContaining(
        \DateTimeImmutable $timestamp,
        BillingCycleDefinition $cycle,
        string $timezone,
    ): TimeRange {
        $tz = new \DateTimeZone($timezone);
        $local = $timestamp->setTimezone($tz);

        if ($cycle->anchor !== null) {
            $start = $cycle->anchor->setTimezone($tz);
            $end = $this->advance($start, $cycle->length, $cycle->unit);

            while ($local < $start) {
                $end = $start;
                $start = $this->advance($start, -$cycle->length, $cycle->unit);
            }
            while ($local >= $end) {
                $start = $end;
                $end = $this->advance($end, $cycle->length, $cycle->unit);
            }

            return new TimeRange($start, $end);
        }

        $start = $this->alignNaturalStart($local, $cycle->length, $cycle->unit);
        $end = $this->advance($start, $cycle->length, $cycle->unit);
        return new TimeRange($start, $end);
    }

    /** @return list<TimeRange> */
    public function periodsOverlapping(
        TimeRange $range,
        BillingCycleDefinition $cycle,
        string $timezone,
    ): array {
        $periods = [];
        $period = $this->periodContaining($range->from, $cycle, $timezone);

        while ($period->from < $range->to) {
            if ($period->to > $range->from) {
                $periods[] = $period;
            }
            $period = new TimeRange(
                $period->to,
                $this->advance($period->to, $cycle->length, $cycle->unit),
            );
        }

        return $periods;
    }

    /** @param list<TimeRange> $periods */
    public function rangeCoversWholePeriods(TimeRange $range, array $periods): bool
    {
        if ($periods === []) {
            return false;
        }

        return $this->sameInstant($range->from, $periods[0]->from)
            && $this->sameInstant($range->to, $periods[array_key_last($periods)]->to);
    }

    public function advance(
        \DateTimeImmutable $dateTime,
        int $length,
        BillingCycleUnit $unit,
    ): \DateTimeImmutable {
        if ($length === 0) {
            return $dateTime;
        }
        $sign = $length > 0 ? '+' : '-';
        $absolute = abs($length);

        return match ($unit) {
            BillingCycleUnit::DAY => $dateTime->modify(sprintf('%s%d day', $sign, $absolute)),
            BillingCycleUnit::WEEK => $dateTime->modify(sprintf('%s%d week', $sign, $absolute)),
            BillingCycleUnit::MONTH => $dateTime->modify(sprintf('%s%d month', $sign, $absolute)),
            BillingCycleUnit::YEAR => $dateTime->modify(sprintf('%s%d year', $sign, $absolute)),
        };
    }

    private function alignNaturalStart(
        \DateTimeImmutable $dateTime,
        int $length,
        BillingCycleUnit $unit,
    ): \DateTimeImmutable {
        return match ($unit) {
            BillingCycleUnit::DAY => $this->alignDay($dateTime, $length),
            BillingCycleUnit::WEEK => $this->alignWeek($dateTime, $length),
            BillingCycleUnit::MONTH => $this->alignMonth($dateTime, $length),
            BillingCycleUnit::YEAR => $this->alignYear($dateTime, $length),
        };
    }

    private function alignDay(\DateTimeImmutable $dateTime, int $length): \DateTimeImmutable
    {
        $dateTime = $dateTime->setTime(0, 0, 0);
        if ($length === 1) {
            return $dateTime;
        }
        $epoch = new \DateTimeImmutable('1970-01-01 00:00:00', $dateTime->getTimezone());
        $days = (int)$epoch->diff($dateTime)->format('%r%a');
        $remainder = (($days % $length) + $length) % $length;
        return $remainder === 0 ? $dateTime : $dateTime->modify(sprintf('-%d day', $remainder));
    }

    private function alignWeek(\DateTimeImmutable $dateTime, int $length): \DateTimeImmutable
    {
        $dateTime = $dateTime->setTime(0, 0, 0)->modify('monday this week');
        if ($length === 1) {
            return $dateTime;
        }
        $epoch = new \DateTimeImmutable('1970-01-05 00:00:00', $dateTime->getTimezone());
        $days = (int)$epoch->diff($dateTime)->format('%r%a');
        $weeks = intdiv($days, 7);
        $remainder = (($weeks % $length) + $length) % $length;
        return $remainder === 0 ? $dateTime : $dateTime->modify(sprintf('-%d week', $remainder));
    }

    private function alignMonth(\DateTimeImmutable $dateTime, int $length): \DateTimeImmutable
    {
        $dateTime = $dateTime
            ->setDate((int)$dateTime->format('Y'), (int)$dateTime->format('m'), 1)
            ->setTime(0, 0, 0);
        if ($length === 1) {
            return $dateTime;
        }
        $months = ((int)$dateTime->format('Y') * 12) + ((int)$dateTime->format('n') - 1);
        $remainder = (($months % $length) + $length) % $length;
        return $remainder === 0 ? $dateTime : $dateTime->modify(sprintf('-%d month', $remainder));
    }

    private function alignYear(\DateTimeImmutable $dateTime, int $length): \DateTimeImmutable
    {
        $dateTime = $dateTime
            ->setDate((int)$dateTime->format('Y'), 1, 1)
            ->setTime(0, 0, 0);
        if ($length === 1) {
            return $dateTime;
        }
        $year = (int)$dateTime->format('Y');
        $remainder = (($year % $length) + $length) % $length;
        return $remainder === 0 ? $dateTime : $dateTime->modify(sprintf('-%d year', $remainder));
    }

    private function sameInstant(\DateTimeImmutable $left, \DateTimeImmutable $right): bool
    {
        return $left->getTimestamp() === $right->getTimestamp();
    }
}
