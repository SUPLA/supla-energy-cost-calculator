<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\ReferenceInterval;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;
use Symfony\Component\Yaml\Yaml;

final class CostCalculatorTest extends TestCase
{
    #[DataProvider('tariffProfileCases')]
    public function testTariffProfile(string $name, array $definition, array $case): void
    {
        $deltas = array_map(
            fn(array $delta): EnergyDelta => $this->deltaFromEnd(
                $delta['datetime'],
                (string)$delta['import'],
                (string)($delta['export'] ?? '0'),
            ),
            $case['deltas'],
        );
        $references = [];
        foreach ($case['references'] ?? [] as $source => $intervals) {
            $references[$source] = array_map(
                fn(array $interval): ReferenceInterval => new ReferenceInterval(
                    new \DateTimeImmutable($interval['from']),
                    new \DateTimeImmutable($interval['to']),
                    (string)$interval['value'],
                    $interval['unit'] ?? null,
                ),
                $intervals,
            );
        }

        $range = isset($case['query'])
            ? new TimeRange(new \DateTimeImmutable($case['query']['from']), new \DateTimeImmutable($case['query']['to']))
            : new TimeRange($deltas[0]->from, $deltas[array_key_last($deltas)]->to);

        $expected = $case['expected'];
        $includeIntervals = isset($expected['selections'])
            || isset($expected['result']['intervals'])
            || isset($expected['result']['charges']);
        $calculator = new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource($references));

        if (isset($expected['exception'])) {
            $this->expectException((string)$expected['exception']);
            if (isset($expected['messageContains'])) {
                $this->expectExceptionMessage((string)$expected['messageContains']);
            }
            $calculator->calculate(
                'meter',
                $range,
                $definition,
                new CalculationOptions(includeIntervals: $includeIntervals),
            );
            return;
        }

        $result = $calculator->calculate(
            'meter',
            $range,
            $definition,
            new CalculationOptions(includeIntervals: $includeIntervals),
        );

        if (isset($expected['result'])) {
            $this->assertExpectedSubset($expected['result'], $result->jsonSerialize(), $name);
        } else {
            self::assertSame((string)$expected['total'], $result->total, $name);
            foreach ($expected['byComponent'] as $component => $value) {
                self::assertSame((string)$value, $result->usageBasedByComponent[$component], $name);
            }
        }

        foreach (array_values($expected['selections'] ?? []) as $intervalIndex => $selection) {
            self::assertSame(
                $selection['zone'],
                $result->intervals[$intervalIndex]['components'][$selection['componentIndex']]['selection'],
                $name,
            );
        }
    }

    public static function tariffProfileCases(): iterable
    {
        foreach (glob(__DIR__ . '/../Fixtures/Tariffs/*.yml') ?: [] as $file) {
            $profile = Yaml::parseFile($file);
            $definitionFile = substr($file, 0, -4) . '.json';
            $definition = json_decode((string)file_get_contents($definitionFile), true, 512, JSON_THROW_ON_ERROR);
            foreach ($profile['cases'] as $caseName => $case) {
                yield basename($file, '.yml') . '_' . $caseName => [
                    basename($file, '.yml') . '_' . $caseName,
                    $definition,
                    $case,
                ];
            }
        }
    }

    public function testArbitraryRangeReturnsUsageAndPeriodicDefinitionWithoutAddingPeriodicCharge(): void
    {
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycle' => [
                'anchor' => '2026-01-15',
                'length' => 1,
                'unit' => 'MONTH',
            ],
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => null,
                'components' => [
                    [
                        'id' => 'energy',
                        'category' => 'ENERGY',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'rate' => ['type' => 'CONSTANT', 'value' => '0.50', 'unit' => 'PLN/kWh'],
                    ],
                    [
                        'id' => 'fixed',
                        'category' => 'NETWORK',
                        'quantity' => ['type' => 'PERIOD', 'period' => 'MONTH', 'prorate' => false],
                        'rate' => ['type' => 'CONSTANT', 'value' => '12.00', 'unit' => 'PLN/month'],
                    ],
                ],
            ]],
        ];
        $deltas = [
            $this->delta('2026-01-19T00:00:00+01:00', '2026-01-19T00:15:00+01:00', '2'),
        ];

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange(
                new \DateTimeImmutable('2026-01-19T00:00:00+01:00'),
                new \DateTimeImmutable('2026-01-26T00:00:00+01:00'),
            ),
            $definition,
            new CalculationOptions(includeIntervals: true),
        );

        self::assertSame('1', $result->usageBasedTotal);
        self::assertNull($result->periodicTotal);
        self::assertNull($result->total);
        self::assertSame('2', $result->usage[QuantityType::ACTIVE_ENERGY_IMPORT->value]);
        self::assertSame('2', $result->intervals[0]['usage'][QuantityType::ACTIVE_ENERGY_IMPORT->value]);
        self::assertSame('1', $result->intervals[0]['costs']['total']);
        self::assertSame('12.00', $result->periodicCharges[0]['definition']['rate']);
        self::assertNull($result->periodicCharges[0]['calculated']);
        self::assertFalse($result->billingContext['requestedRangeCoversWholePeriods']);
    }

    public function testFixing1ReferenceRate(): void
    {
        $deltas = [$this->delta('2026-01-01T10:00:00Z', '2026-01-01T10:15:00Z', '2')];
        $references = new InMemoryReferenceDataSource([
            'PL.TGE.FIXING1' => [new ReferenceInterval(
                new \DateTimeImmutable('2026-01-01T10:00:00Z'),
                new \DateTimeImmutable('2026-01-01T11:00:00Z'),
                '450',
                'PLN/MWh',
            )],
        ]);
        $definition = $this->singleComponentDefinition([
            'id' => 'energy',
            'category' => 'ENERGY',
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'rate' => [
                'type' => 'REFERENCE',
                'source' => 'PL.TGE.FIXING1',
                'multiplier' => '0.001',
                'add' => '0.05',
                'unit' => 'PLN/kWh',
            ],
        ]);

        $calculator = new CostCalculator(new InMemoryEnergyDeltaSource($deltas), $references);
        $result = $calculator->calculate(
            'meter',
            new TimeRange(new \DateTimeImmutable('2026-01-01T10:00:00Z'), new \DateTimeImmutable('2026-01-01T10:15:00Z')),
            $definition,
        );

        self::assertSame('1', $result->usageBasedTotal);
        self::assertSame('1', $result->total);
    }

    public function testPdgszSelectsG14DynamicZone(): void
    {
        $deltas = [$this->delta('2026-01-01T18:00:00Z', '2026-01-01T18:15:00Z', '1.25')];
        $references = new InMemoryReferenceDataSource([
            'PL.PSE.PDGSZ' => [new ReferenceInterval(
                new \DateTimeImmutable('2026-01-01T18:00:00Z'),
                new \DateTimeImmutable('2026-01-01T19:00:00Z'),
                '3',
            )],
        ]);
        $definition = $this->singleComponentDefinition([
            'id' => 'network',
            'category' => 'NETWORK',
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'selector' => [
                'type' => 'REFERENCE',
                'source' => 'PL.PSE.PDGSZ',
                'mapping' => ['0' => 'S1', '1' => 'S2', '2' => 'S3', '3' => 'S4'],
            ],
            'rate' => [
                'type' => 'ZONED',
                'rates' => ['S1' => '0.1', 'S2' => '0.2', 'S3' => '0.5', 'S4' => '2.0'],
                'unit' => 'PLN/kWh',
            ],
        ]);

        $calculator = new CostCalculator(new InMemoryEnergyDeltaSource($deltas), $references);
        $result = $calculator->calculate(
            'meter',
            new TimeRange(new \DateTimeImmutable('2026-01-01T18:00:00Z'), new \DateTimeImmutable('2026-01-01T18:15:00Z')),
            $definition,
            new CalculationOptions(includeIntervals: true),
        );

        self::assertSame('2.5', $result->usageBasedTotal);
        self::assertSame('2.5', $result->usageBasedByZone['S4']);
        self::assertSame('S4', $result->intervals[0]['components'][0]['selection']);
    }

    public function testHolidayScheduleOverridesRegularWeekdayRule(): void
    {
        $deltas = [
            $this->delta('2026-05-01T10:00:00Z', '2026-05-01T10:15:00Z', '1'),
            $this->delta('2026-05-08T10:00:00Z', '2026-05-08T10:15:00Z', '1'),
        ];
        $definition = $this->singleComponentDefinition([
            'id' => 'network',
            'category' => 'NETWORK',
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'selector' => [
                'type' => 'WEEKLY_SCHEDULE',
                'timezone' => 'Europe/Warsaw',
                'calendar' => 'PL_PUBLIC_HOLIDAYS',
                'rules' => [
                    ['zone' => 'OFF_PEAK', 'days' => ['HOLIDAY'], 'from' => '00:00', 'to' => '24:00'],
                    ['zone' => 'PEAK', 'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI'], 'from' => '00:00', 'to' => '24:00'],
                    ['zone' => 'OFF_PEAK', 'days' => ['SAT', 'SUN'], 'from' => '00:00', 'to' => '24:00'],
                ],
            ],
            'rate' => [
                'type' => 'ZONED',
                'rates' => ['PEAK' => '1.00', 'OFF_PEAK' => '0.10'],
                'unit' => 'PLN/kWh',
            ],
        ]);

        $calculator = new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource());
        $result = $calculator->calculate(
            'meter',
            new TimeRange(new \DateTimeImmutable('2026-05-01T10:00:00Z'), new \DateTimeImmutable('2026-05-08T10:15:00Z')),
            $definition,
            new CalculationOptions(includeIntervals: true),
        );

        self::assertSame('1.1', $result->usageBasedTotal);
        self::assertSame('OFF_PEAK', $result->intervals[0]['components'][0]['selection']);
        self::assertSame('PEAK', $result->intervals[1]['components'][0]['selection']);
    }

    public function testHolidayRuleWithoutCalendarIsRejected(): void
    {
        $definition = $this->singleComponentDefinition([
            'id' => 'network',
            'category' => 'NETWORK',
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'selector' => [
                'type' => 'WEEKLY_SCHEDULE',
                'timezone' => 'Europe/Warsaw',
                'rules' => [
                    ['zone' => 'OFF_PEAK', 'days' => ['HOLIDAY'], 'from' => '00:00', 'to' => '24:00'],
                ],
            ],
            'rate' => ['type' => 'ZONED', 'rates' => ['OFF_PEAK' => '0.1']],
        ]);

        $this->expectException(\Supla\EnergyCostCalculator\Exception\DefinitionException::class);
        (new \Supla\EnergyCostCalculator\Definition\BillingDefinitionParser())->parse($definition);
    }

    public function testRulesCanChangeOverTime(): void
    {
        $deltas = [
            $this->delta('2026-01-01T00:00:00Z', '2026-01-01T00:15:00Z', '1'),
            $this->delta('2026-07-01T00:00:00Z', '2026-07-01T00:15:00Z', '1'),
        ];
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'periods' => [
                [
                    'validFrom' => '2026-01-01T00:00:00Z',
                    'validTo' => '2026-07-01T00:00:00Z',
                    'components' => [[
                        'id' => 'energy',
                        'category' => 'ENERGY',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'rate' => ['type' => 'CONSTANT', 'value' => '0.50'],
                    ]],
                ],
                [
                    'validFrom' => '2026-07-01T00:00:00Z',
                    'validTo' => null,
                    'components' => [[
                        'id' => 'energy',
                        'category' => 'ENERGY',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'rate' => ['type' => 'CONSTANT', 'value' => '0.75'],
                    ]],
                ],
            ],
        ];

        $calculator = new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource());
        $result = $calculator->calculate(
            'meter',
            new TimeRange(new \DateTimeImmutable('2026-01-01T00:00:00Z'), new \DateTimeImmutable('2026-07-01T00:15:00Z')),
            $definition,
        );

        self::assertSame('1.25', $result->usageBasedTotal);
        self::assertSame('1.25', $result->total);
    }

    public function testBillingCycleHistoryBuildsTransitionalSummaryAndByZone(): void
    {
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycles' => [
                [
                    'validFrom' => null,
                    'validTo' => '2026-07-01T00:00:00+02:00',
                    'anchor' => '2026-01-15',
                    'length' => 1,
                    'unit' => 'MONTH',
                ],
                [
                    'validFrom' => '2026-07-01T00:00:00+02:00',
                    'validTo' => null,
                    'anchor' => '2026-07-01',
                    'length' => 1,
                    'unit' => 'MONTH',
                ],
            ],
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [
                    [
                        'id' => 'energy',
                        'category' => 'ENERGY',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'selector' => [
                            'type' => 'WEEKLY_SCHEDULE',
                            'timezone' => 'Europe/Warsaw',
                            'rules' => [[
                                'zone' => 'Z1',
                                'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'],
                                'from' => '00:00',
                                'to' => '24:00',
                            ]],
                        ],
                        'rate' => ['type' => 'ZONED', 'rates' => ['Z1' => '2']],
                    ],
                    [
                        'id' => 'billing-fee',
                        'category' => 'SERVICE',
                        'quantity' => ['type' => 'PERIOD', 'period' => 'BILLING_PERIOD', 'prorate' => false],
                        'rate' => ['type' => 'CONSTANT', 'value' => '7'],
                    ],
                ],
            ]],
        ];
        $deltas = [
            $this->delta('2026-06-16T10:00:00+02:00', '2026-06-16T10:15:00+02:00', '1'),
            $this->delta('2026-07-02T10:00:00+02:00', '2026-07-02T10:15:00+02:00', '1'),
        ];

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange(
                new \DateTimeImmutable('2026-06-15T00:00:00+02:00'),
                new \DateTimeImmutable('2026-08-01T00:00:00+02:00'),
            ),
            $definition,
        );

        self::assertNull($result->billingCycle);
        self::assertSame('4', $result->usageBasedTotal);
        self::assertSame('4', $result->usageBasedByZone['Z1']);
        self::assertSame('14', $result->periodicTotal);
        self::assertSame('18', $result->total);
        self::assertCount(2, $result->billingPeriods);
        self::assertTrue($result->billingPeriods[0]['transitional']);
        self::assertSame('2026-06-15T00:00:00+02:00', $result->billingPeriods[0]['from']);
        self::assertSame('2026-07-01T00:00:00+02:00', $result->billingPeriods[0]['to']);
        self::assertSame('2', $result->billingPeriods[0]['costs']['usageBased']['byZone']['Z1']);
        self::assertSame('7', $result->billingPeriods[0]['costs']['periodic']['total']);
        self::assertSame('9', $result->billingPeriods[0]['costs']['total']);
        self::assertFalse($result->billingPeriods[1]['transitional']);
        self::assertSame('9', $result->billingPeriods[1]['costs']['total']);
    }

    public function testProratedMonthlyFeeUsesNominalPeriodWhenBillingCycleIsCutShort(): void
    {
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycles' => [
                [
                    'validFrom' => null,
                    'validTo' => '2026-07-01T00:00:00+02:00',
                    'anchor' => '2026-01-15',
                    'length' => 1,
                    'unit' => 'MONTH',
                ],
                [
                    'validFrom' => '2026-07-01T00:00:00+02:00',
                    'validTo' => null,
                    'anchor' => '2026-07-01',
                    'length' => 1,
                    'unit' => 'MONTH',
                ],
            ],
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [[
                    'id' => 'monthly-fee',
                    'category' => 'SERVICE',
                    'quantity' => ['type' => 'PERIOD', 'period' => 'MONTH', 'prorate' => true],
                    'rate' => ['type' => 'CONSTANT', 'value' => '30'],
                ]],
            ]],
        ];

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource([]),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange(
                new \DateTimeImmutable('2026-06-15T00:00:00+02:00'),
                new \DateTimeImmutable('2026-07-01T00:00:00+02:00'),
            ),
            $definition,
        );

        self::assertTrue($result->billingPeriods[0]['transitional']);
        self::assertEqualsWithDelta(16.0, (float)$result->periodicTotal, 0.000001);
        self::assertEqualsWithDelta(16.0, (float)$result->billingPeriods[0]['costs']['periodic']['total'], 0.000001);
    }

    private function delta(string $from, string $to, string $import, string $export = '0'): EnergyDelta
    {
        return new EnergyDelta(new \DateTimeImmutable($from), new \DateTimeImmutable($to), [
            QuantityType::ACTIVE_ENERGY_IMPORT->value => $import,
            QuantityType::ACTIVE_ENERGY_EXPORT->value => $export,
            QuantityType::ACTIVE_ENERGY_BALANCED_IMPORT->value => $import,
            QuantityType::ACTIVE_ENERGY_BALANCED_EXPORT->value => $export,
        ]);
    }

    private function deltaFromEnd(string $end, string $import, string $export = '0'): EnergyDelta
    {
        $to = new \DateTimeImmutable($end);
        return $this->delta(
            $to->modify('-15 minutes')->format(DATE_ATOM),
            $to->format(DATE_ATOM),
            $import,
            $export,
        );
    }

    private function singleComponentDefinition(array $component): array
    {
        return [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00Z',
                'validTo' => null,
                'components' => [$component],
            ]],
        ];
    }

    private function assertExpectedSubset(mixed $expected, mixed $actual, string $path): void
    {
        if (!is_array($expected)) {
            self::assertSame($expected, $actual, $path);
            return;
        }

        self::assertIsArray($actual, $path);
        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $actual, $path . '.' . $key);
            $this->assertExpectedSubset($value, $actual[$key], $path . '.' . $key);
        }
    }
}
