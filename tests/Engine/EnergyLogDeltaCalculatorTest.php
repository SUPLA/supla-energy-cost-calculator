<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\EnergyLogDeltaCalculator;
use Supla\EnergyCostCalculator\Model\EnergyLog;

final class EnergyLogDeltaCalculatorTest extends TestCase
{
    public function testInterpolatesIrregularReadingsIntoQuarterHourSlots(): void
    {
        $deltas = (new EnergyLogDeltaCalculator())->calculate([
            $this->log('2026-06-11 12:05:00', 1000),
            $this->log('2026-06-11 12:20:00', 2000),
            $this->log('2026-06-11 12:50:00', 5000),
        ]);

        self::assertSame([1333, 1500], array_column(array_map(fn($delta) => $delta->values, $deltas), 'energy'));
        self::assertSame('2026-06-11 12:30:00', $deltas[0]->to->format('Y-m-d H:i:s'));
    }

    public function testHandlesResetsAndMissingReadings(): void
    {
        $deltas = (new EnergyLogDeltaCalculator())->calculate([
            $this->log('2026-06-11 12:00:00', 1000),
            $this->log('2026-06-11 12:15:00', 1200),
            $this->log('2026-06-11 12:30:00', 0),
            $this->log('2026-06-11 12:45:00', 250),
        ]);

        self::assertSame([200, 0, 0], array_column(array_map(fn($delta) => $delta->values, $deltas), 'energy'));
    }

    public function testStartsAfterPreviouslyProcessedSlot(): void
    {
        $deltas = (new EnergyLogDeltaCalculator())->calculate([
            $this->log('2026-06-11 12:15:00', 2000),
            $this->log('2026-06-11 12:30:00', 3500),
        ], new DateTimeImmutable('2026-06-11 12:15:00', new DateTimeZone('UTC')));

        self::assertCount(1, $deltas);
        self::assertSame(1500, $deltas[0]->values['energy']);
        self::assertSame('2026-06-11 12:30:00', $deltas[0]->to->format('Y-m-d H:i:s'));
    }

    private function log(string $at, ?int $energy): EnergyLog
    {
        return new EnergyLog(new DateTimeImmutable($at, new DateTimeZone('UTC')), ['energy' => $energy]);
    }
}
