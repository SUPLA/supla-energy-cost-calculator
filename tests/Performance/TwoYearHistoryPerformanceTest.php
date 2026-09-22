<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Contract\EnergyDeltaSource;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

#[Group('performance')]
final class TwoYearHistoryPerformanceTest extends TestCase
{
    private const MAX_CALCULATION_TIME_SECONDS = 10.0;

    public function testCalculatesTwoYearsOfQuarterHourHistory(): void
    {
        $range = new TimeRange(
            new \DateTimeImmutable('2024-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $deltaSource = new class implements EnergyDeltaSource {
            public function getDeltas(string $meterId, TimeRange $range): iterable
            {
                $intervalLength = new \DateInterval('PT15M');
                $from = $range->from;

                while ($from < $range->to) {
                    $to = $from->add($intervalLength);
                    yield new EnergyDelta($from, $to, [
                        QuantityType::ACTIVE_ENERGY_IMPORT->value => '0.25',
                    ]);
                    $from = $to;
                }
            }
        };
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'UTC',
            'billingCycle' => [
                'anchor' => '2024-01-01T00:00:00Z',
                'length' => 1,
                'unit' => 'MONTH',
            ],
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [[
                    'id' => 'energy',
                    'category' => 'ENERGY',
                    'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                    'rate' => ['type' => 'CONSTANT', 'value' => '0.8', 'unit' => 'PLN/kWh'],
                ]],
            ]],
        ];
        $calculator = new CostCalculator($deltaSource, new InMemoryReferenceDataSource());

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(70_176, $result->processedDeltaCount);
        self::assertSame('17544', $result->usage[QuantityType::ACTIVE_ENERGY_IMPORT->value]);
        self::assertSame('14035.2', $result->total);
        self::assertLessThan(
            self::MAX_CALCULATION_TIME_SECONDS,
            $elapsedSeconds,
            sprintf('Two-year cost calculation took %.3f seconds.', $elapsedSeconds),
        );
    }
}
