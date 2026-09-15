<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class ScheduleAndPeriodicChargeTest extends TestCase
{
    public function testScheduleRangeCanCrossMidnightAndUsesStartingDay(): void
    {
        $deltas = [
            $this->delta('2026-01-05T22:15:00Z', '2026-01-05T22:30:00Z'), // Mon 23:15 Warsaw
            $this->delta('2026-01-06T04:15:00Z', '2026-01-06T04:30:00Z'), // Tue 05:15 Warsaw, still Monday rule
        ];
        $definition = $this->definition([[
            'id' => 'network',
            'category' => 'NETWORK',
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'selector' => [
                'type' => 'WEEKLY_SCHEDULE',
                'timezone' => 'Europe/Warsaw',
                'rules' => [[
                    'zone' => 'NIGHT',
                    'days' => ['MON'],
                    'time_ranges' => [['from' => '22:00', 'to' => '06:00']],
                ]],
            ],
            'rate' => ['type' => 'ZONED', 'rates' => ['NIGHT' => '0.10'], 'unit' => 'PLN/kWh'],
        ]]);

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange($deltas[0]->from, $deltas[1]->to),
            $definition,
            new CalculationOptions(includeIntervals: true),
        );

        self::assertSame('0.2', $result->total);
        self::assertSame('NIGHT', $result->intervals[0]['components'][0]['selection']);
        self::assertSame('NIGHT', $result->intervals[1]['components'][0]['selection']);
    }

    public function testScheduleSelectsSeasonIncludingSeasonWrappingNewYear(): void
    {
        $deltas = [
            $this->delta('2026-01-05T10:00:00Z', '2026-01-05T10:15:00Z'),
            $this->delta('2026-06-15T10:00:00Z', '2026-06-15T10:15:00Z'),
        ];
        $definition = $this->definition([[
            'id' => 'network',
            'category' => 'NETWORK',
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'selector' => [
                'type' => 'WEEKLY_SCHEDULE',
                'timezone' => 'Europe/Warsaw',
                'seasons' => [
                    ['id' => 'SUMMER', 'from' => '--04-01', 'to' => '--10-01'],
                    ['id' => 'WINTER', 'from' => '--10-01', 'to' => '--04-01'],
                ],
                'rules' => [
                    [
                        'zone' => 'WINTER',
                        'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'],
                        'season' => 'WINTER',
                        'time_ranges' => [['from' => '00:00', 'to' => '24:00']],
                    ],
                    [
                        'zone' => 'SUMMER',
                        'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'],
                        'season' => 'SUMMER',
                        'time_ranges' => [['from' => '00:00', 'to' => '24:00']],
                    ],
                ],
            ],
            'rate' => [
                'type' => 'ZONED',
                'rates' => ['WINTER' => '0.20', 'SUMMER' => '0.10'],
                'unit' => 'PLN/kWh',
            ],
        ]]);

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange($deltas[0]->from, $deltas[1]->to),
            $definition,
            new CalculationOptions(includeIntervals: true),
        );

        self::assertSame('0.3', $result->total);
        self::assertSame('WINTER', $result->intervals[0]['components'][0]['selection']);
        self::assertSame('SUMMER', $result->intervals[1]['components'][0]['selection']);
    }

    public function testMonthlyFixedCostIsAddedForEveryOverlappingCalendarMonth(): void
    {
        $deltas = [
            $this->delta('2026-01-15T10:00:00Z', '2026-01-15T10:15:00Z'),
            $this->delta('2026-02-15T10:00:00Z', '2026-02-15T10:15:00Z'),
        ];
        $definition = $this->definition([
            [
                'id' => 'energy',
                'category' => 'ENERGY',
                'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                'rate' => ['type' => 'CONSTANT', 'value' => '0.50', 'unit' => 'PLN/kWh'],
            ],
            [
                'id' => 'fixed-network',
                'category' => 'NETWORK',
                'quantity' => ['type' => 'PERIOD', 'period' => 'MONTH', 'prorate' => false],
                'rate' => ['type' => 'CONSTANT', 'value' => '10.00', 'unit' => 'PLN/month'],
            ],
        ]);

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange(
                new \DateTimeImmutable('2026-01-15T10:00:00Z'),
                new \DateTimeImmutable('2026-02-15T10:15:00Z'),
            ),
            $definition,
        );

        self::assertSame('21', $result->total);
        self::assertSame('1', $result->byComponent['energy']);
        self::assertSame('20', $result->byComponent['fixed-network']);
    }

    private function delta(string $from, string $to): EnergyDelta
    {
        return new EnergyDelta(new \DateTimeImmutable($from), new \DateTimeImmutable($to), [
            QuantityType::ACTIVE_ENERGY_IMPORT->value => '1',
            QuantityType::ACTIVE_ENERGY_EXPORT->value => '0',
            QuantityType::ACTIVE_ENERGY_BALANCED_IMPORT->value => '1',
            QuantityType::ACTIVE_ENERGY_BALANCED_EXPORT->value => '0',
        ]);
    }

    private function definition(array $components): array
    {
        return [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => null,
                'components' => $components,
            ]],
        ];
    }
}
