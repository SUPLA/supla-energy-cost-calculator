<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Engine;

use DateTimeImmutable;
use DateTimeZone;
use Supla\EnergyCostCalculator\Model\EnergyLog;
use Supla\EnergyCostCalculator\Model\EnergyLogDelta;

final class EnergyLogDeltaCalculator
{
    public function __construct(private readonly int $slotDurationInSeconds = 900)
    {
        if ($slotDurationInSeconds <= 0) {
            throw new \InvalidArgumentException('Slot duration must be positive.');
        }
    }

    /**
     * @param iterable<EnergyLog> $logs Ordered cumulative meter readings.
     * @return list<EnergyLogDelta>
     */
    public function calculate(iterable $logs, ?DateTimeImmutable $processedThrough = null): array
    {
        $logs = is_array($logs) ? $logs : iterator_to_array($logs, false);
        if (count($logs) < 2) {
            return [];
        }

        $fields = [];
        foreach ($logs as $log) {
            foreach ($log->values as $field => $_) {
                $fields[$field] = true;
            }
        }
        $fields = array_keys($fields);
        $firstLog = $logs[0];
        $firstSlot = $this->firstSlotDate($firstLog->at, $processedThrough);
        $firstSlotTimestamp = $firstSlot->getTimestamp();
        $firstLogTimestamp = $firstLog->at->getTimestamp();
        $lastLogTimestamp = $logs[array_key_last($logs)]->at->getTimestamp();
        if ($firstSlotTimestamp > $lastLogTimestamp) {
            return [];
        }

        $currentSlotEndTimestamp = null;
        $currentSlotTotals = $this->emptyValues($fields);
        $lastAcceptedValues = $this->initialAcceptedValues($firstLog, $fields);
        $deltas = [];

        for ($i = 0, $count = count($logs) - 1; $i < $count; ++$i) {
            $logA = $logs[$i];
            $logB = $logs[$i + 1];
            $timestampA = $logA->at->getTimestamp();
            $timestampB = $logB->at->getTimestamp();
            if ($timestampB <= $timestampA) {
                continue;
            }

            $intervalDeltas = $this->intervalDeltas($logB, $lastAcceptedValues, $fields);
            $slotEndTimestamp = intdiv($timestampA, $this->slotDurationInSeconds) * $this->slotDurationInSeconds
                + $this->slotDurationInSeconds;
            while ($slotEndTimestamp <= $lastLogTimestamp && $slotEndTimestamp - $this->slotDurationInSeconds < $timestampB) {
                $slotStartTimestamp = $slotEndTimestamp - $this->slotDurationInSeconds;
                if ($slotEndTimestamp >= $firstSlotTimestamp && $slotStartTimestamp >= $firstLogTimestamp) {
                    $currentSlotEndTimestamp ??= $slotEndTimestamp;
                    while ($currentSlotEndTimestamp < $slotEndTimestamp) {
                        $deltas[] = $this->delta($currentSlotEndTimestamp, $currentSlotTotals);
                        $currentSlotEndTimestamp += $this->slotDurationInSeconds;
                        $currentSlotTotals = $this->emptyValues($fields);
                    }

                    $overlapStart = max($slotStartTimestamp, $timestampA);
                    $overlapEnd = min($slotEndTimestamp, $timestampB);
                    if ($overlapStart < $overlapEnd) {
                        $ratio = ($overlapEnd - $overlapStart) / ($timestampB - $timestampA);
                        foreach ($fields as $field) {
                            $currentSlotTotals[$field] += $intervalDeltas[$field] * $ratio;
                        }
                    }
                }
                $slotEndTimestamp += $this->slotDurationInSeconds;
            }
            $lastAcceptedValues = $this->updateAcceptedValues($logB, $lastAcceptedValues, $fields);
        }

        if ($currentSlotEndTimestamp !== null) {
            $deltas[] = $this->delta($currentSlotEndTimestamp, $currentSlotTotals);
        }

        return $deltas;
    }

    /** @param list<string> $fields @return array<string, float> */
    private function emptyValues(array $fields): array
    {
        return array_fill_keys($fields, 0.0);
    }

    /** @param list<string> $fields @return array<string, int|float|null> */
    private function initialAcceptedValues(EnergyLog $log, array $fields): array
    {
        $accepted = array_fill_keys($fields, null);
        foreach ($fields as $field) {
            if (($log->values[$field] ?? null) > 0) {
                $accepted[$field] = $log->values[$field];
            }
        }
        return $accepted;
    }

    /** @param array<string, int|float|null> $accepted @param list<string> $fields @return array<string, float> */
    private function intervalDeltas(EnergyLog $log, array $accepted, array $fields): array
    {
        $deltas = $this->emptyValues($fields);
        foreach ($fields as $field) {
            $current = $log->values[$field] ?? null;
            if ($current !== null && $current !== 0 && $accepted[$field] !== null && $current > $accepted[$field]) {
                $deltas[$field] = $current - $accepted[$field];
            }
        }
        return $deltas;
    }

    /** @param array<string, int|float|null> $accepted @param list<string> $fields @return array<string, int|float|null> */
    private function updateAcceptedValues(EnergyLog $log, array $accepted, array $fields): array
    {
        foreach ($fields as $field) {
            $value = $log->values[$field] ?? null;
            if ($value !== null && $value > 0) {
                $accepted[$field] = $value;
            }
        }
        return $accepted;
    }

    /** @param array<string, float> $values */
    private function delta(int $toTimestamp, array $values): EnergyLogDelta
    {
        $to = (new DateTimeImmutable('@' . $toTimestamp))->setTimezone(new DateTimeZone('UTC'));
        return new EnergyLogDelta(
            $to->modify('-' . $this->slotDurationInSeconds . ' seconds'),
            $to,
            array_map(fn(float $value): int => (int) round($value), $values)
        );
    }

    private function firstSlotDate(DateTimeImmutable $firstLog, ?DateTimeImmutable $processedThrough): DateTimeImmutable
    {
        if ($processedThrough !== null) {
            return $processedThrough->modify('+' . $this->slotDurationInSeconds . ' seconds');
        }
        $timestamp = $firstLog->getTimestamp();
        $nextSlotTimestamp = intdiv($timestamp, $this->slotDurationInSeconds) * $this->slotDurationInSeconds;
        if ($nextSlotTimestamp <= $timestamp) {
            $nextSlotTimestamp += $this->slotDurationInSeconds;
        }
        return (new DateTimeImmutable('@' . $nextSlotTimestamp))->setTimezone(new DateTimeZone('UTC'));
    }
}
