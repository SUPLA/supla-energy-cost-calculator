<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use Supla\EnergyCostCalculator\Definition\ComponentDefinition;
use Supla\EnergyCostCalculator\Math\DecimalMath;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class PeriodicChargeCalculator
{
    public function __construct(private readonly DecimalMath $math)
    {
    }

    public function cost(ComponentDefinition $component, TimeRange $range, string $timezone): string
    {
        $period = (string)$component->quantity->options['period'];
        $prorate = (bool)($component->quantity->options['prorate'] ?? false);
        $rate = (string)$component->rate->config['value'];
        $tz = new \DateTimeZone($timezone);

        $cursor = $this->periodStart($range->from, $period, $tz);
        $totalUnits = '0';

        while ($cursor < $range->to) {
            $next = $this->advance($cursor, $period);
            $bucket = new TimeRange($cursor, $next);
            $overlap = $bucket->intersection($range);
            if ($overlap !== null) {
                $units = '1';
                if ($prorate) {
                    $overlapSeconds = (string)($overlap->to->getTimestamp() - $overlap->from->getTimestamp());
                    $bucketSeconds = (string)($bucket->to->getTimestamp() - $bucket->from->getTimestamp());
                    $units = $this->math->divide($overlapSeconds, $bucketSeconds);
                }
                $totalUnits = $this->math->add($totalUnits, $units);
            }
            $cursor = $next;
        }

        return $this->math->multiply($totalUnits, $rate);
    }

    private function periodStart(\DateTimeImmutable $timestamp, string $period, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $local = $timestamp->setTimezone($timezone);
        return match ($period) {
            'DAY' => $local->setTime(0, 0),
            'WEEK' => $local->modify('monday this week')->setTime(0, 0),
            'MONTH' => $local->modify('first day of this month')->setTime(0, 0),
            default => throw new \LogicException("Unsupported period '$period'."),
        };
    }

    private function advance(\DateTimeImmutable $timestamp, string $period): \DateTimeImmutable
    {
        return match ($period) {
            'DAY' => $timestamp->modify('+1 day'),
            'WEEK' => $timestamp->modify('+1 week'),
            'MONTH' => $timestamp->modify('+1 month'),
            default => throw new \LogicException("Unsupported period '$period'."),
        };
    }
}
