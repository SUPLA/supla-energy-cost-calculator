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
    public function testConstantEnergyAndPeriodicFee(): void
    {
        $deltas = [
            $this->delta('2026-01-01T00:00:00Z', '2026-01-01T00:15:00Z', '1.5'),
            $this->delta('2026-01-01T00:15:00Z', '2026-01-01T00:30:00Z', '0.5'),
        ];
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00Z',
                'validTo' => null,
                'components' => [
                    [
                        'id' => 'energy',
                        'category' => 'ENERGY',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'rate' => ['type' => 'CONSTANT', 'value' => '0.50', 'unit' => 'PLN/kWh'],
                    ],
                    [
                        'id' => 'service',
                        'category' => 'SERVICE',
                        'quantity' => ['type' => 'PERIOD', 'period' => 'DAY', 'prorate' => false],
                        'rate' => ['type' => 'CONSTANT', 'value' => '2.00', 'unit' => 'PLN/day'],
                    ],
                ],
            ]],
        ];

        $calculator = new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource());
        $result = $calculator->calculate('meter', new TimeRange(new \DateTimeImmutable('2026-01-01T00:00:00Z'), new \DateTimeImmutable('2026-01-01T00:30:00Z')), $definition);

        self::assertSame('3', $result->total);
        self::assertSame('1', $result->byComponent['energy']);
        self::assertSame('2', $result->byComponent['service']);
    }

    #[DataProvider('tariffProfileCases')]
    public function testTariffProfile(string $name, array $definition, array $case): void
    {
        $deltas = array_map(
            fn(array $delta): EnergyDelta => $this->deltaFromEnd($delta['datetime'], (string)$delta['import']),
            $case['deltas'],
        );
        $calculator = new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource());
        $result = $calculator->calculate(
            'meter',
            new TimeRange($deltas[0]->from, $deltas[array_key_last($deltas)]->to),
            $definition,
            new CalculationOptions(includeIntervals: isset($case['expected']['selections'])),
        );

        self::assertSame((string)$case['expected']['total'], $result->total, $name);
        foreach ($case['expected']['byComponent'] as $component => $expected) {
            self::assertSame((string)$expected, $result->byComponent[$component], $name);
        }
        foreach ($case['expected']['selections'] ?? [] as $intervalIndex => $selection) {
            self::assertSame($selection['zone'], $result->intervals[$intervalIndex]['components'][$selection['componentIndex']]['selection'], $name);
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
        $result = $calculator->calculate('meter', new TimeRange(new \DateTimeImmutable('2026-01-01T10:00:00Z'), new \DateTimeImmutable('2026-01-01T10:15:00Z')), $definition);

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

        self::assertSame('2.5', $result->total);
        self::assertSame('S4', $result->intervals[0]['components'][0]['selection']);
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
        $result = $calculator->calculate('meter', new TimeRange(new \DateTimeImmutable('2026-01-01T00:00:00Z'), new \DateTimeImmutable('2026-07-01T00:15:00Z')), $definition);

        self::assertSame('1.25', $result->total);
    }

    public function testHolidayScheduleOverridesRegularWeekdayRule(): void
    {
        $deltas = [
            $this->delta('2026-05-01T10:00:00Z', '2026-05-01T10:15:00Z', '1'), // Friday, 12:00 Europe/Warsaw
            $this->delta('2026-05-08T10:00:00Z', '2026-05-08T10:15:00Z', '1'), // regular Friday
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

        self::assertSame('1.1', $result->total);
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

    private function delta(string $from, string $to, string $import): EnergyDelta
    {
        return new EnergyDelta(new \DateTimeImmutable($from), new \DateTimeImmutable($to), [
            QuantityType::ACTIVE_ENERGY_IMPORT->value => $import,
            QuantityType::ACTIVE_ENERGY_EXPORT->value => '0',
            QuantityType::ACTIVE_ENERGY_BALANCED_IMPORT->value => $import,
            QuantityType::ACTIVE_ENERGY_BALANCED_EXPORT->value => '0',
        ]);
    }

    private function deltaFromEnd(string $end, string $import): EnergyDelta
    {
        $to = new \DateTimeImmutable($end);
        return $this->delta($to->modify('-15 minutes')->format(DATE_ATOM), $to->format(DATE_ATOM), $import);
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
}
