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

        self::assertSame('0.2', $result->usageBasedTotal);
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

        self::assertSame('0.3', $result->usageBasedTotal);
        self::assertSame('WINTER', $result->intervals[0]['components'][0]['selection']);
        self::assertSame('SUMMER', $result->intervals[1]['components'][0]['selection']);
    }

    public function testMonthlyFixedCostUsesBillingAnchorAndIsAddedOnlyForWholeBillingPeriod(): void
    {
        $deltas = [
            $this->delta('2026-01-20T10:00:00+01:00', '2026-01-20T10:15:00+01:00'),
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
        ], [
            'anchor' => '2026-01-15T00:00:00+01:00',
            'length' => 1,
            'unit' => 'MONTH',
        ]);

        $calculator = new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        );

        $partial = $calculator->calculate(
            'meter',
            new TimeRange(
                new \DateTimeImmutable('2026-01-20T00:00:00+01:00'),
                new \DateTimeImmutable('2026-01-27T00:00:00+01:00'),
            ),
            $definition,
        );
        self::assertSame('0.5', $partial->usageBasedTotal);
        self::assertNull($partial->periodicTotal);
        self::assertNull($partial->total);
        self::assertNull($partial->periodicCharges[0]['calculated']);

        $full = $calculator->calculate(
            'meter',
            new TimeRange(
                new \DateTimeImmutable('2026-01-15T00:00:00+01:00'),
                new \DateTimeImmutable('2026-02-15T00:00:00+01:00'),
            ),
            $definition,
        );
        self::assertSame('0.5', $full->usageBasedTotal);
        self::assertSame('10', $full->periodicTotal);
        self::assertSame('10.5', $full->total);
        self::assertSame('1', $full->periodicCharges[0]['calculated']['units']);
        self::assertSame('10', $full->periodicCharges[0]['calculated']['amount']);
    }

    public function testBillingPeriodFixedCostIsChargedOncePerBillingCycle(): void
    {
        $definition = $this->definition([[ 
            'id' => 'billing-fee',
            'category' => 'SERVICE',
            'quantity' => ['type' => 'PERIOD', 'period' => 'BILLING_PERIOD', 'prorate' => false],
            'rate' => ['type' => 'CONSTANT', 'value' => '7.00', 'unit' => 'PLN/period'],
        ]], [
            'anchor' => '2026-01-15T00:00:00+01:00',
            'length' => 2,
            'unit' => 'MONTH',
        ]);

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource([]),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange(
                new \DateTimeImmutable('2026-01-15T00:00:00+01:00'),
                new \DateTimeImmutable('2026-03-15T00:00:00+01:00'),
            ),
            $definition,
        );

        self::assertSame('7', $result->periodicTotal);
        self::assertSame('7', $result->total);
        self::assertSame('1', $result->periodicCharges[0]['calculated']['units']);
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

    private function definition(array $components, ?array $billingCycle = null): array
    {
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => null,
                'components' => $components,
            ]],
        ];
        if ($billingCycle !== null) {
            $definition['billingCycle'] = $billingCycle;
        }
        return $definition;
    }
}
