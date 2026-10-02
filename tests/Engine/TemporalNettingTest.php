<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CalculationProblemPolicy;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\ReferenceInterval;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class TemporalNettingTest extends TestCase
{
    public function testPeriodInMinutesWithoutStrategyIsRejected(): void
    {
        $definition = $this->definition([
            'type' => 'ACTIVE_ENERGY_IMPORT',
            'periodInMinutes' => 60,
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('periodInMinutes requires quantity.strategy');
        (new BillingDefinitionParser())->parse($definition);
    }

    public function testTemporalNettingIsCurrentlyLimitedToActiveEnergyImport(): void
    {
        $definition = $this->definition([
            'type' => 'ACTIVE_ENERGY_EXPORT',
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('currently supports ACTIVE_ENERGY_IMPORT only');
        (new BillingDefinitionParser())->parse($definition);
    }

    public function testRepeatedDstHourProducesTwoDistinctNettingCharges(): void
    {
        $definition = $this->definition([
            'type' => 'ACTIVE_ENERGY_IMPORT',
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ]);
        $deltas = [];
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $startUtc = new \DateTimeImmutable('2026-10-25T00:00:00Z');
        for ($i = 0; $i < 8; $i++) {
            $from = $startUtc->modify(sprintf('+%d minutes', $i * 15))->setTimezone($timezone);
            $to = $startUtc->modify(sprintf('+%d minutes', ($i + 1) * 15))->setTimezone($timezone);
            $deltas[] = $this->delta($from, $to, '0.25', '0');
        }

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange($deltas[0]->from, $deltas[array_key_last($deltas)]->to),
            $definition,
            new CalculationOptions(includeIntervals: true, includeCharges: true),
        );

        self::assertCount(2, $result->charges);
        self::assertSame('1', $result->charges[0]['quantity']['value']);
        self::assertSame('1', $result->charges[1]['quantity']['value']);
        self::assertNotSame($result->charges[0]['from'], $result->charges[1]['from']);
    }

    public function testNettingWindowCannotCrossBillingCycleBoundary(): void
    {
        $definition = $this->definition([
            'type' => 'ACTIVE_ENERGY_IMPORT',
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ]);
        $definition['billingCycles'] = [
            [
                'validFrom' => null,
                'validTo' => '2026-01-02T10:30:00+01:00',
                'anchor' => '2026-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ],
            [
                'validFrom' => '2026-01-02T10:30:00+01:00',
                'validTo' => null,
                'anchor' => '2026-01-02',
                'length' => 1,
                'unit' => 'MONTH',
            ],
        ];
        $deltas = [];
        $from = new \DateTimeImmutable('2026-01-02T10:00:00+01:00');
        for ($i = 0; $i < 4; $i++) {
            $deltaFrom = $from->modify(sprintf('+%d minutes', $i * 15));
            $deltas[] = $this->delta($deltaFrom, $deltaFrom->modify('+15 minutes'), '0.25', '0');
        }

        $this->expectException(\Supla\EnergyCostCalculator\Exception\CalculationException::class);
        $this->expectExceptionMessage('crosses a billing-period boundary');

        (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange($deltas[0]->from, $deltas[array_key_last($deltas)]->to),
            $definition,
        );
    }

    public function testSkipAffectedCalculationProblemPolicySkipsMissingReferenceNettingWindow(): void
    {
        $definition = $this->definition([
            'type' => 'ACTIVE_ENERGY_IMPORT',
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ]);
        $definition['periods'][0]['components'][0]['rate'] = [
            'type' => 'REFERENCE',
            'source' => 'PL.TGE.FIXING1_HOURLY',
            'sourceUnit' => 'PLN/MWh',
            'multiplier' => '0.001',
        ];
        $from = new \DateTimeImmutable('2026-01-02T10:00:00+01:00');
        $deltas = [];
        for ($i = 0; $i < 8; $i++) {
            $deltaFrom = $from->modify(sprintf('+%d minutes', $i * 15));
            $deltas[] = $this->delta($deltaFrom, $deltaFrom->modify('+15 minutes'), '1', '0');
        }

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource([
                'PL.TGE.FIXING1_HOURLY' => [new ReferenceInterval(
                    $from->modify('+1 hour'),
                    $from->modify('+2 hours'),
                    '1000',
                    'PLN/MWh',
                )],
            ]),
        ))->calculate(
            'meter',
            new TimeRange($from, $from->modify('+2 hours')),
            $definition,
            new CalculationOptions(
                includeIntervals: true,
                includeCharges: true,
                calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED,
            ),
        );

        self::assertTrue($result->jsonSerialize()['incomplete']);
        self::assertSame('TEMPORAL_NETTING_WINDOW', $result->warnings[0]['scope']);
        self::assertSame($from->format(DATE_ATOM), $result->warnings[0]['from']);
        self::assertSame('4', $result->usage[QuantityType::ACTIVE_ENERGY_IMPORT->value]);
        self::assertSame('4', $result->costs['gross']['usageBased']['total']);
        self::assertCount(4, $result->intervals);
        self::assertCount(1, $result->charges);
        self::assertSame($from->modify('+1 hour')->format(DATE_ATOM), $result->charges[0]['from']);
    }

    public function testSkipAffectedCalculationProblemPolicySkipsWholeNettingWindowAndContinues(): void
    {
        $definition = $this->definition([
            'type' => 'ACTIVE_ENERGY_IMPORT',
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ]);
        $definition['periods'][0]['components'][0]['selector'] = [
            'type' => 'WEEKLY_SCHEDULE',
            'timezone' => 'Europe/Warsaw',
            'rules' => [['zone' => 'DAY', 'days' => ['FRI'], 'from' => '11:00', 'to' => '12:00']],
        ];
        $definition['periods'][0]['components'][0]['rate'] = [
            'type' => 'ZONED',
            'rates' => ['DAY' => '1'],
            'unit' => 'PLN/kWh',
        ];
        $from = new \DateTimeImmutable('2026-01-02T10:00:00+01:00');
        $deltas = [];
        for ($i = 0; $i < 8; $i++) {
            $deltaFrom = $from->modify(sprintf('+%d minutes', $i * 15));
            $deltas[] = $this->delta($deltaFrom, $deltaFrom->modify('+15 minutes'), '1', '0');
        }

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange($from, $from->modify('+2 hours')),
            $definition,
            new CalculationOptions(
                includeIntervals: true,
                includeCharges: true,
                calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED,
            ),
        );

        self::assertTrue($result->jsonSerialize()['incomplete']);
        self::assertSame('CALCULATION_PROBLEM', $result->warnings[0]['code']);
        self::assertSame('TEMPORAL_NETTING_WINDOW', $result->warnings[0]['scope']);
        self::assertSame($from->format(DATE_ATOM), $result->warnings[0]['from']);
        self::assertSame('4', $result->usage[QuantityType::ACTIVE_ENERGY_IMPORT->value]);
        self::assertSame('4', $result->costs['gross']['usageBased']['total']);
        self::assertCount(4, $result->intervals);
        self::assertCount(1, $result->charges);
        self::assertSame($from->modify('+1 hour')->format(DATE_ATOM), $result->charges[0]['from']);
    }

    private function definition(array $quantity): array
    {
        return [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'taxRuleSets' => [[
                'validFrom' => null,
                'validTo' => null,
                'rules' => [[
                    'id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['SUPPLIER_FIXED'],
                    'rate' => '0.23', 'base' => 'CURRENT_SUBTOTAL',
                ]],
            ]],
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => null,
                'components' => [[
                    'id' => 'energy',
                    'kind' => 'ENERGY_PURCHASE',
                    'category' => 'ENERGY',
                    'taxTreatment' => ['included' => []],
                    'quantity' => $quantity,
                    'rate' => ['type' => 'CONSTANT', 'value' => '1'],
                ]],
            ]],
        ];
    }

    private function delta(\DateTimeImmutable $from, \DateTimeImmutable $to, string $import, string $export): EnergyDelta
    {
        return new EnergyDelta($from, $to, [
            QuantityType::ACTIVE_ENERGY_IMPORT->value => $import,
            QuantityType::ACTIVE_ENERGY_EXPORT->value => $export,
        ]);
    }
}
