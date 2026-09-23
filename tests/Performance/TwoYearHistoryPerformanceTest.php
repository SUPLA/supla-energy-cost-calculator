<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Contract\EnergyDeltaSource;
use Supla\EnergyCostCalculator\Contract\ReferenceDataSource;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\ReferenceDataId;
use Supla\EnergyCostCalculator\Model\ReferenceInterval;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

#[Group('performance')]
final class TwoYearHistoryPerformanceTest extends TestCase
{
    private const MAX_CALCULATION_TIME_SECONDS = 15.0;

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
                'anchor' => '2024-01-01',
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

    public function testCalculatesPresetComposedHistory(): void
    {
        $range = new TimeRange(
            new \DateTimeImmutable('2025-12-31T23:00:00Z'),
            new \DateTimeImmutable('2026-12-31T23:00:00Z'),
        );
        $deltaSource = new class implements EnergyDeltaSource {
            public function getDeltas(string $meterId, TimeRange $range): iterable
            {
                $from = $range->from;
                while ($from < $range->to) {
                    $to = $from->add(new \DateInterval('PT15M'));
                    yield new EnergyDelta($from, $to, [
                        QuantityType::ACTIVE_ENERGY_IMPORT->value => '0.25',
                    ]);
                    $from = $to;
                }
            }
        };
        $definition = (new CostPlanCompiler())->compile([
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'priceBasis' => 'NET',
            'billingCycles' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'anchor' => '2026-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ]],
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'components' => [
                    [
                        'kind' => 'ENERGY_PURCHASE',
                        'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                        'componentId' => 'energy-purchase',
                        'values' => ['energy.rate' => '0.71'],
                    ],
                    [
                        'kind' => 'DISTRIBUTION_VARIABLE',
                        'presetId' => 'PL.ENERGA_OPERATOR.G12.2026',
                        'componentId' => 'distribution-variable',
                        'values' => [],
                    ],
                ],
            ]],
        ]);
        $calculator = new CostCalculator($deltaSource, new InMemoryReferenceDataSource());

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(35_040, $result->processedDeltaCount);
        self::assertSame('8760', $result->usage[QuantityType::ACTIVE_ENERGY_IMPORT->value]);
        self::assertArrayHasKey('energy-purchase', $result->usageBasedByComponent);
        self::assertArrayHasKey('distribution-variable', $result->usageBasedByComponent);
        self::assertLessThan(
            self::MAX_CALCULATION_TIME_SECONDS,
            $elapsedSeconds,
            sprintf('Preset-composed calculation took %.3f seconds.', $elapsedSeconds),
        );
    }

    public function testCalculatesTwoYearHistoryAcrossG11AndG12Periods(): void
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
            'timezone' => 'Europe/Warsaw',
            'billingCycle' => [
                'anchor' => '2024-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ],
            'periods' => [
                [
                    'validFrom' => null,
                    'validTo' => '2025-01-01T00:00:00Z',
                    'components' => [
                        [
                            'id' => 'g11-energy-purchase',
                            'category' => 'ENERGY',
                            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                            'rate' => ['type' => 'CONSTANT', 'value' => '0.50', 'unit' => 'PLN/kWh'],
                        ],
                        [
                            'id' => 'g11-distribution-variable',
                            'category' => 'NETWORK',
                            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                            'rate' => ['type' => 'CONSTANT', 'value' => '0.30', 'unit' => 'PLN/kWh'],
                        ],
                    ],
                ],
                [
                    'validFrom' => '2025-01-01T00:00:00Z',
                    'validTo' => null,
                    'components' => [
                        [
                            'id' => 'g12-energy-purchase',
                            'category' => 'ENERGY',
                            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                            'rate' => ['type' => 'CONSTANT', 'value' => '0.50', 'unit' => 'PLN/kWh'],
                        ],
                        [
                            'id' => 'g12-distribution-variable',
                            'category' => 'NETWORK',
                            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                            'selector' => [
                                'type' => 'WEEKLY_SCHEDULE',
                                'timezone' => 'Europe/Warsaw',
                                'rules' => [
                                    [
                                        'zone' => 'NIGHT',
                                        'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'],
                                        'time_ranges' => [
                                            ['from' => '22:00', 'to' => '06:00'],
                                            ['from' => '13:00', 'to' => '15:00'],
                                        ],
                                    ],
                                    [
                                        'zone' => 'DAY',
                                        'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'],
                                        'time_ranges' => [
                                            ['from' => '06:00', 'to' => '13:00'],
                                            ['from' => '15:00', 'to' => '22:00'],
                                        ],
                                    ],
                                ],
                            ],
                            'rate' => [
                                'type' => 'ZONED',
                                'rates' => ['DAY' => '0.32', 'NIGHT' => '0.14'],
                                'unit' => 'PLN/kWh',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $calculator = new CostCalculator($deltaSource, new InMemoryReferenceDataSource());

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(70_176, $result->processedDeltaCount);
        self::assertSame('17544', $result->usage[QuantityType::ACTIVE_ENERGY_IMPORT->value]);
        self::assertSame('4392', $result->usageBasedByComponent['g11-energy-purchase']);
        self::assertSame('2635.2', $result->usageBasedByComponent['g11-distribution-variable']);
        self::assertSame('4380', $result->usageBasedByComponent['g12-energy-purchase']);
        self::assertSame('2146.2', $result->usageBasedByComponent['g12-distribution-variable']);
        self::assertSame('13553.4', $result->total);
        self::assertLessThan(
            self::MAX_CALCULATION_TIME_SECONDS,
            $elapsedSeconds,
            sprintf('Two-year G11-to-G12 calculation took %.3f seconds.', $elapsedSeconds),
        );
    }

    public function testCalculatesTwoYearHistoryWithG14DynamicDistributionSource(): void
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
        $referenceDataSource = new class implements ReferenceDataSource {
            public function get(ReferenceDataId $id, TimeRange $range): iterable
            {
                $intervalLength = new \DateInterval('PT1H');
                $from = $range->from;
                $zone = 0;

                while ($from < $range->to) {
                    $to = $from->add($intervalLength);
                    yield new ReferenceInterval($from, $to, (string)$zone);
                    $from = $to;
                    $zone = ($zone + 1) % 4;
                }
            }
        };
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycle' => [
                'anchor' => '2024-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ],
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [
                    [
                        'id' => 'energy-purchase',
                        'category' => 'ENERGY',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'rate' => ['type' => 'CONSTANT', 'value' => '0.50', 'unit' => 'PLN/kWh'],
                    ],
                    [
                        'id' => 'distribution-variable',
                        'category' => 'NETWORK',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'selector' => [
                            'type' => 'REFERENCE',
                            'source' => 'PL.PSE.PDGSZ',
                            'mapping' => ['0' => 'S1', '1' => 'S2', '2' => 'S3', '3' => 'S4'],
                        ],
                        'rate' => [
                            'type' => 'ZONED',
                            'rates' => ['S1' => '0.10', 'S2' => '0.20', 'S3' => '0.50', 'S4' => '2.00'],
                            'unit' => 'PLN/kWh',
                        ],
                    ],
                ],
            ]],
        ];
        $calculator = new CostCalculator($deltaSource, $referenceDataSource);

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(70_176, $result->processedDeltaCount);
        self::assertSame('8772', $result->usageBasedByComponent['energy-purchase']);
        self::assertSame('12280.8', $result->usageBasedByComponent['distribution-variable']);
        self::assertSame('438.6', $result->usageBasedByZone['S1']);
        self::assertSame('877.2', $result->usageBasedByZone['S2']);
        self::assertSame('2193', $result->usageBasedByZone['S3']);
        self::assertSame('8772', $result->usageBasedByZone['S4']);
        self::assertSame('21052.8', $result->total);
        self::assertLessThan(
            self::MAX_CALCULATION_TIME_SECONDS,
            $elapsedSeconds,
            sprintf('Two-year G14 dynamic calculation took %.3f seconds.', $elapsedSeconds),
        );
    }

    public function testCalculatesTwoYearHistoryWithHourlyTemporalNetting(): void
    {
        $range = $this->twoYearRange();
        $definition = $this->referenceRateDefinition([
            'type' => 'ACTIVE_ENERGY_IMPORT',
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ]);
        $calculator = new CostCalculator(
            $this->quarterHourDeltaSource('0.25', '0.05'),
            $this->constantReferenceDataSource('PL.TGE.FIXING1_HOURLY', new \DateInterval('PT1H'), '400'),
        );

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(70_176, $result->processedDeltaCount);
        self::assertSame('7017.6', $result->total);
        $this->assertCalculationTime($elapsedSeconds, 'Two-year temporal-netting calculation');
    }

    public function testCalculatesTwoYearHistoryWithDetailedIntervalOutput(): void
    {
        $range = $this->twoYearRange();
        $definition = $this->constantRateDefinition('0.8');
        $calculator = new CostCalculator($this->quarterHourDeltaSource(), new InMemoryReferenceDataSource());

        $startedAt = hrtime(true);
        $result = $calculator->calculate(
            'meter',
            $range,
            $definition,
            new CalculationOptions(includeIntervals: true),
        );
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(70_176, $result->processedDeltaCount);
        self::assertCount(70_176, $result->intervals);
        self::assertCount(70_176, $result->charges);
        self::assertSame('14035.2', $result->total);
        $this->assertCalculationTime($elapsedSeconds, 'Two-year detailed calculation');
    }

    public function testCalculatesTwoYearHistoryWithMultipleConcurrentComponents(): void
    {
        $range = $this->twoYearRange();
        $referenceDataSource = new class implements ReferenceDataSource {
            public function get(ReferenceDataId $id, TimeRange $range): iterable
            {
                $from = $range->from;
                $interval = new \DateInterval('PT1H');
                $zone = 0;

                while ($from < $range->to) {
                    $to = $from->add($interval);
                    yield new ReferenceInterval($from, $to, $id->value === 'PL.PSE.PDGSZ' ? (string)$zone : '400');
                    $from = $to;
                    $zone = ($zone + 1) % 4;
                }
            }
        };
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'UTC',
            'billingCycle' => ['anchor' => '2024-01-01', 'length' => 1, 'unit' => 'MONTH'],
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [
                    $this->constantEnergyComponent('energy', '0.50'),
                    [
                        'id' => 'market-adjustment',
                        'category' => 'ENERGY',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'rate' => [
                            'type' => 'REFERENCE',
                            'source' => 'PL.TGE.FIXING1_HOURLY',
                            'multiplier' => '0.001',
                            'add' => '0.10',
                        ],
                    ],
                    [
                        'id' => 'dynamic-distribution',
                        'category' => 'NETWORK',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'selector' => [
                            'type' => 'REFERENCE',
                            'source' => 'PL.PSE.PDGSZ',
                            'mapping' => ['0' => 'S1', '1' => 'S2', '2' => 'S3', '3' => 'S4'],
                        ],
                        'rate' => ['type' => 'ZONED', 'rates' => ['S1' => '0.10', 'S2' => '0.20', 'S3' => '0.50', 'S4' => '2.00']],
                    ],
                    $this->constantEnergyComponent('network-supplement', '0.10'),
                    [
                        'id' => 'monthly-fee',
                        'category' => 'SERVICE',
                        'quantity' => ['type' => 'PERIOD', 'period' => 'MONTH', 'prorate' => false],
                        'rate' => ['type' => 'CONSTANT', 'value' => '1'],
                    ],
                ],
            ]],
        ];
        $calculator = new CostCalculator($this->quarterHourDeltaSource(), $referenceDataSource);

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame('31603.2', $result->total);
        self::assertSame('24', $result->periodicTotal);
        self::assertSame('12280.8', $result->usageBasedByComponent['dynamic-distribution']);
        $this->assertCalculationTime($elapsedSeconds, 'Two-year multi-component calculation');
    }

    public function testCalculatesTwoYearHistoryWithHolidayAndSeasonalSchedule(): void
    {
        $range = $this->twoYearRange();
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [[
                    'id' => 'scheduled-distribution',
                    'category' => 'NETWORK',
                    'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                    'selector' => [
                        'type' => 'WEEKLY_SCHEDULE',
                        'timezone' => 'Europe/Warsaw',
                        'calendar' => 'PL_PUBLIC_HOLIDAYS',
                        'seasons' => [
                            ['id' => 'WINTER', 'from' => '--11-01', 'to' => '--03-01'],
                            ['id' => 'SUMMER', 'from' => '--03-01', 'to' => '--11-01'],
                        ],
                        'rules' => [
                            ['zone' => 'HOLIDAY', 'days' => ['HOLIDAY'], 'from' => '00:00', 'to' => '24:00', 'priority' => 1],
                            ['zone' => 'WINTER', 'season' => 'WINTER', 'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], 'from' => '00:00', 'to' => '24:00', 'priority' => 10],
                            ['zone' => 'NIGHT', 'season' => 'SUMMER', 'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], 'from' => '22:00', 'to' => '06:00', 'priority' => 20],
                            ['zone' => 'DAY', 'season' => 'SUMMER', 'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], 'from' => '06:00', 'to' => '22:00', 'priority' => 30],
                            ['zone' => 'FALLBACK', 'season' => '*', 'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], 'from' => '00:00', 'to' => '24:00', 'priority' => 100],
                        ],
                    ],
                    'rate' => ['type' => 'ZONED', 'rates' => ['HOLIDAY' => '0.10', 'WINTER' => '0.20', 'NIGHT' => '0.30', 'DAY' => '0.40', 'FALLBACK' => '0.40']],
                ]],
            ]],
        ];
        $calculator = new CostCalculator($this->quarterHourDeltaSource(), new InMemoryReferenceDataSource());

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(70_176, $result->processedDeltaCount);
        self::assertArrayHasKey('HOLIDAY', $result->usageBasedByZone);
        self::assertArrayHasKey('WINTER', $result->usageBasedByZone);
        self::assertArrayHasKey('NIGHT', $result->usageBasedByZone);
        self::assertArrayHasKey('DAY', $result->usageBasedByZone);
        self::assertArrayHasKey('FALLBACK', $result->usageBasedByZone);
        $this->assertCalculationTime($elapsedSeconds, 'Two-year holiday and seasonal schedule calculation');
    }

    public function testCalculatesHistoricalBillingCyclesAndPeriodicCharges(): void
    {
        $range = new TimeRange(
            new \DateTimeImmutable('2024-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-15T00:00:00Z'),
        );
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'UTC',
            'billingCycles' => [
                [
                    'validFrom' => null,
                    'validTo' => '2025-01-15T00:00:00Z',
                    'anchor' => '2024-01-01',
                    'length' => 1,
                    'unit' => 'MONTH',
                ],
                [
                    'validFrom' => '2025-01-15T00:00:00Z',
                    'validTo' => null,
                    'anchor' => '2025-01-15',
                    'length' => 1,
                    'unit' => 'MONTH',
                ],
            ],
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [
                    $this->constantEnergyComponent('energy', '0.50'),
                    [
                        'id' => 'monthly-fee',
                        'category' => 'SERVICE',
                        'quantity' => ['type' => 'PERIOD', 'period' => 'MONTH', 'prorate' => false],
                        'rate' => ['type' => 'CONSTANT', 'value' => '1'],
                    ],
                ],
            ]],
        ];
        $calculator = new CostCalculator($this->quarterHourDeltaSource(), new InMemoryReferenceDataSource());

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(71_520, $result->processedDeltaCount);
        self::assertCount(25, $result->billingPeriods);
        self::assertTrue($result->billingPeriods[12]['transitional']);
        self::assertSame('25', $result->periodicTotal);
        $this->assertCalculationTime($elapsedSeconds, 'Historical billing-cycle calculation');
    }

    public function testCalculatesTwoYearHistoryWithQuarterHourReferenceSeries(): void
    {
        $range = $this->twoYearRange();
        $definition = $this->referenceRateDefinition(['type' => 'ACTIVE_ENERGY_IMPORT']);
        $calculator = new CostCalculator(
            $this->quarterHourDeltaSource(),
            $this->constantReferenceDataSource('PL.TGE.FIXING1_HOURLY', new \DateInterval('PT15M'), '400'),
        );

        $startedAt = hrtime(true);
        $result = $calculator->calculate('meter', $range, $definition);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertSame(70_176, $result->processedDeltaCount);
        self::assertSame('8772', $result->total);
        $this->assertCalculationTime($elapsedSeconds, 'Two-year quarter-hour reference calculation');
    }

    private function twoYearRange(): TimeRange
    {
        return new TimeRange(
            new \DateTimeImmutable('2024-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    private function constantRateDefinition(string $rate): array
    {
        return [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'UTC',
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [$this->constantEnergyComponent('energy', $rate)],
            ]],
        ];
    }

    private function referenceRateDefinition(array $quantity): array
    {
        return [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'UTC',
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [[
                    'id' => 'energy',
                    'category' => 'ENERGY',
                    'quantity' => $quantity,
                    'rate' => [
                        'type' => 'REFERENCE',
                        'source' => 'PL.TGE.FIXING1_HOURLY',
                        'multiplier' => '0.001',
                        'add' => '0.10',
                    ],
                ]],
            ]],
        ];
    }

    private function constantEnergyComponent(string $id, string $rate): array
    {
        return [
            'id' => $id,
            'category' => 'ENERGY',
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'rate' => ['type' => 'CONSTANT', 'value' => $rate],
        ];
    }

    private function quarterHourDeltaSource(string $import = '0.25', string $export = '0'): EnergyDeltaSource
    {
        return new class ($import, $export) implements EnergyDeltaSource {
            public function __construct(private readonly string $import, private readonly string $export)
            {
            }

            public function getDeltas(string $meterId, TimeRange $range): iterable
            {
                $interval = new \DateInterval('PT15M');
                $from = $range->from;

                while ($from < $range->to) {
                    $to = $from->add($interval);
                    yield new EnergyDelta($from, $to, [
                        QuantityType::ACTIVE_ENERGY_IMPORT->value => $this->import,
                        QuantityType::ACTIVE_ENERGY_EXPORT->value => $this->export,
                    ]);
                    $from = $to;
                }
            }
        };
    }

    private function constantReferenceDataSource(string $source, \DateInterval $interval, string $value): ReferenceDataSource
    {
        return new class ($source, $interval, $value) implements ReferenceDataSource {
            public function __construct(
                private readonly string $source,
                private readonly \DateInterval $interval,
                private readonly string $value,
            ) {
            }

            public function get(ReferenceDataId $id, TimeRange $range): iterable
            {
                if ($id->value !== $this->source) {
                    return;
                }

                $from = $range->from;
                while ($from < $range->to) {
                    $to = $from->add($this->interval);
                    yield new ReferenceInterval($from, $to, $this->value);
                    $from = $to;
                }
            }
        };
    }

    private function assertCalculationTime(float $elapsedSeconds, string $description): void
    {
        self::assertLessThan(
            self::MAX_CALCULATION_TIME_SECONDS,
            $elapsedSeconds,
            sprintf('%s took %.3f seconds.', $description, $elapsedSeconds),
        );
    }
}
