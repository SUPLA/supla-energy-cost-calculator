<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Math\NativeDecimalMath;
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
            'kind' => 'DISTRIBUTION_VARIABLE',
            'category' => 'NETWORK',
            'taxTreatment' => ['included' => []],
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

        self::assertSame('0.2', $result->costs['gross']['usageBased']['total']);
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
            'kind' => 'DISTRIBUTION_VARIABLE',
            'category' => 'NETWORK',
            'taxTreatment' => ['included' => []],
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

        self::assertSame('0.3', $result->costs['gross']['usageBased']['total']);
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
                'kind' => 'ENERGY_PURCHASE',
                'category' => 'ENERGY',
                'taxTreatment' => ['included' => []],
                'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                'rate' => ['type' => 'CONSTANT', 'value' => '0.50', 'unit' => 'PLN/kWh'],
            ],
            [
                'id' => 'fixed-network',
                'kind' => 'DISTRIBUTION_FIXED',
                'category' => 'NETWORK',
                'taxTreatment' => ['included' => []],
                'quantity' => ['type' => 'PERIOD', 'period' => 'MONTH', 'prorate' => false],
                'rate' => ['type' => 'CONSTANT', 'value' => '10.00', 'unit' => 'PLN/month'],
            ],
        ], [
            'anchor' => '2026-01-15',
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
        self::assertSame('0.5', $partial->costs['gross']['usageBased']['total']);
        self::assertNull($partial->costs['gross']['periodic']['total']);
        self::assertNull($partial->costs['gross']['total']);
        self::assertNull($partial->periodicCharges[0]['calculated']);

        $full = $calculator->calculate(
            'meter',
            new TimeRange(
                new \DateTimeImmutable('2026-01-15T00:00:00+01:00'),
                new \DateTimeImmutable('2026-02-15T00:00:00+01:00'),
            ),
            $definition,
        );
        self::assertSame('0.5', $full->costs['gross']['usageBased']['total']);
        self::assertSame('10', $full->costs['gross']['periodic']['total']);
        self::assertSame('10.5', $full->costs['gross']['total']);
        self::assertSame('1', $full->periodicCharges[0]['calculated']['units']);
        self::assertSame('10', $full->periodicCharges[0]['calculated']['amounts']['gross']);

        $json = $full->jsonSerialize();
        self::assertArrayHasKey('net', $json['costs']);
        self::assertArrayHasKey('taxes', $json['costs']);
        self::assertArrayHasKey('gross', $json['costs']);
        self::assertArrayNotHasKey('tax' . 'Exclusive', $json['costs']);
        self::assertArrayNotHasKey('tax' . 'Inclusive', $json['costs']);
        self::assertFinancialInvariant($json['charges'][0]['amounts']);
        self::assertFinancialInvariant($json['periodicCharges'][0]['calculated']['amounts']);
        self::assertCostsInvariant($json['costs']);
        self::assertCostsInvariant($json['billingPeriods'][0]['costs']);
    }

    public function testBillingPeriodFixedCostIsChargedOncePerBillingCycle(): void
    {
        $definition = $this->definition([[
            'id' => 'billing-fee',
            'kind' => 'SUPPLIER_FIXED',
            'category' => 'SERVICE',
            'taxTreatment' => ['included' => []],
            'quantity' => ['type' => 'PERIOD', 'period' => 'BILLING_PERIOD', 'prorate' => false],
            'rate' => ['type' => 'CONSTANT', 'value' => '7.00', 'unit' => 'PLN/period'],
        ]], [
            'anchor' => '2026-01-15',
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

        self::assertSame('7', $result->costs['gross']['periodic']['total']);
        self::assertSame('7', $result->costs['gross']['total']);
        self::assertSame('1', $result->periodicCharges[0]['calculated']['units']);
    }

    public function testPeriodicFeeAddsVatWhenTaxExclusive(): void
    {
        $result = $this->periodicCalculator()->calculate('meter', $this->range('2026-01-15', '2026-02-15'), $this->periodicDefinition([]));

        self::assertSame('10', $result->costs['net']['periodic']['total']);
        self::assertSame('2', $result->costs['taxes']['byTax']['VAT']);
        self::assertSame('2', $result->costs['taxes']['total']);
        self::assertSame('12', $result->costs['gross']['periodic']['total']);
    }

    public function testPeriodicFeeReversesIncludedVat(): void
    {
        $result = $this->periodicCalculator()->calculate(
            'meter',
            $this->range('2026-01-15', '2026-02-15'),
            $this->periodicDefinition(['VAT']),
        );

        self::assertSame('10', $result->costs['net']['periodic']['total']);
        self::assertSame('2', $result->costs['taxes']['byTax']['VAT']);
        self::assertSame('2', $result->costs['taxes']['total']);
        self::assertSame('12', $result->costs['gross']['periodic']['total']);
    }

    public function testPartialPeriodicFeeOutcomeRemainsUnknown(): void
    {
        $result = $this->periodicCalculator()->calculate('meter', $this->range('2026-01-20', '2026-01-27'), $this->periodicDefinition([]));

        self::assertNull($result->costs['net']['periodic']['total']);
        self::assertNull($result->costs['taxes']['total']);
        self::assertNull($result->costs['gross']['total']);
    }

    public function testTaxBoundaryInsidePeriodicBucketThrows(): void
    {
        $definition = $this->periodicDefinition([], [[
            'validFrom' => null,
            'validTo' => '2026-01-20T00:00:00+01:00',
            'rules' => [$this->vatRule()],
        ], [
            'validFrom' => '2026-01-20T00:00:00+01:00',
            'validTo' => null,
            'rules' => [$this->vatRule()],
        ]]);

        $this->expectException(CalculationException::class);
        $this->periodicCalculator()->calculate('meter', $this->range('2026-01-15', '2026-02-15'), $definition);
    }

    public function testTaxBoundaryBetweenPeriodicBucketsSucceeds(): void
    {
        $definition = $this->periodicDefinition([], [[
            'validFrom' => null,
            'validTo' => '2026-02-15T00:00:00+01:00',
            'rules' => [$this->vatRule()],
        ], [
            'validFrom' => '2026-02-15T00:00:00+01:00',
            'validTo' => null,
            'rules' => [$this->vatRule()],
        ]]);

        $result = $this->periodicCalculator()->calculate('meter', $this->range('2026-01-15', '2026-03-15'), $definition);

        self::assertSame('24', $result->costs['gross']['periodic']['total']);
    }

    public function testTaxTreatmentChangeInsidePeriodicBucketThrows(): void
    {
        $definition = $this->periodicDefinition([], null, [
            ['validFrom' => '2026-01-01T00:00:00+01:00', 'validTo' => '2026-01-20T00:00:00+01:00', 'included' => []],
            ['validFrom' => '2026-01-20T00:00:00+01:00', 'validTo' => null, 'included' => ['VAT']],
        ]);

        $this->expectException(CalculationException::class);
        $this->periodicCalculator()->calculate('meter', $this->range('2026-01-15', '2026-02-15'), $definition);
    }

    private function delta(string $from, string $to): EnergyDelta
    {
        return new EnergyDelta(new \DateTimeImmutable($from), new \DateTimeImmutable($to), [
            QuantityType::ACTIVE_ENERGY_IMPORT->value => '1',
            QuantityType::ACTIVE_ENERGY_EXPORT->value => '0',
        ]);
    }

    private function definition(array $components, ?array $billingCycle = null): array
    {
        $definition = [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'taxRuleSets' => [[
                'validFrom' => null,
                'validTo' => null,
                'rules' => [[
                    'id' => 'VAT',
                    'type' => 'PERCENTAGE',
                    'appliesToKinds' => ['ENERGY_PURCHASE'],
                    'rate' => '0',
                    'base' => 'CURRENT_SUBTOTAL',
                ]],
            ]],
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

    private function periodicCalculator(): CostCalculator
    {
        return new CostCalculator(new InMemoryEnergyDeltaSource([]), new InMemoryReferenceDataSource());
    }

    private function range(string $from, string $to): TimeRange
    {
        return new TimeRange(new \DateTimeImmutable($from . 'T00:00:00+01:00'), new \DateTimeImmutable($to . 'T00:00:00+01:00'));
    }

    /** @param list<string> $included @param ?list<array<string, mixed>> $taxRuleSets @param ?list<array<string, mixed>> $periods */
    private function periodicDefinition(array $included, ?array $taxRuleSets = null, ?array $periods = null): array
    {
        $component = static fn(array $included): array => [
            'id' => 'fixed',
            'kind' => 'SUPPLIER_FIXED',
            'category' => 'SERVICE',
            'taxTreatment' => ['included' => $included],
            'quantity' => ['type' => 'PERIOD', 'period' => 'MONTH', 'prorate' => false],
            'rate' => ['type' => 'CONSTANT', 'value' => $included === [] ? '10' : '12', 'unit' => 'PLN/month'],
        ];
        $periods ??= [[
            'validFrom' => '2026-01-01T00:00:00+01:00',
            'validTo' => null,
            'components' => [$component($included)],
        ]];
        foreach ($periods as &$period) {
            $period['components'] ??= [$component($period['included'])];
            unset($period['included']);
        }
        unset($period);

        return [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycle' => ['anchor' => '2026-01-15', 'length' => 1, 'unit' => 'MONTH'],
            'taxRuleSets' => $taxRuleSets ?? [['validFrom' => null, 'validTo' => null, 'rules' => [$this->vatRule()]]],
            'periods' => $periods,
        ];
    }

    /** @return array<string, mixed> */
    private function vatRule(): array
    {
        return ['id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['SUPPLIER_FIXED'], 'rate' => '0.2', 'base' => 'CURRENT_SUBTOTAL'];
    }

    /** @param array<string, mixed> $amounts */
    private static function assertFinancialInvariant(array $amounts): void
    {
        self::assertArrayHasKey('net', $amounts);
        self::assertArrayHasKey('taxes', $amounts);
        self::assertArrayHasKey('gross', $amounts);
        self::assertArrayNotHasKey('tax' . 'Exclusive', $amounts);
        self::assertArrayNotHasKey('tax' . 'Inclusive', $amounts);
        self::assertSame($amounts['gross'], (new NativeDecimalMath())->add($amounts['net'], $amounts['taxTotal']));
    }

    /** @param array<string, mixed> $costs */
    private static function assertCostsInvariant(array $costs): void
    {
        self::assertArrayHasKey('net', $costs);
        self::assertArrayHasKey('taxes', $costs);
        self::assertArrayHasKey('gross', $costs);
        self::assertArrayNotHasKey('tax' . 'Exclusive', $costs);
        self::assertArrayNotHasKey('tax' . 'Inclusive', $costs);
        self::assertSame(
            $costs['gross']['total'],
            (new NativeDecimalMath())->add($costs['net']['total'], $costs['taxes']['total']),
        );
    }
}
