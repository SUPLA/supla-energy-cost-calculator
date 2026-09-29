<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\ReferenceInterval;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class QuantityAllocationTest extends TestCase
{
    public function testAllocationRequiresTemporalStrategy(): void
    {
        $definition = $this->definition([
            'type' => 'ACTIVE_ENERGY_IMPORT',
            'allocation' => [
                'strategy' => 'EQUAL',
                'periodInMinutes' => 15,
            ],
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('allocation requires quantity.strategy');
        (new BillingDefinitionParser())->parse($definition);
    }

    public function testAllocationPeriodMustDivideParentWindow(): void
    {
        $definition = $this->definition([
            'type' => 'ACTIVE_ENERGY_IMPORT',
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
            'allocation' => [
                'strategy' => 'EQUAL',
                'periodInMinutes' => 17,
            ],
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('must divide quantity.periodInMinutes exactly');
        (new BillingDefinitionParser())->parse($definition);
    }

    public function testEqualAllocationSplitsNettedHourAcrossQuarterHourRates(): void
    {
        $from = new \DateTimeImmutable('2026-01-02T10:00:00+01:00');
        $to = new \DateTimeImmutable('2026-01-02T11:00:00+01:00');
        $delta = new EnergyDelta($from, $to, [
            QuantityType::ACTIVE_ENERGY_IMPORT->value => '1.2',
            QuantityType::ACTIVE_ENERGY_EXPORT->value => '0.4',
        ]);

        $references = [];
        foreach (['400', '500', '600', '700'] as $index => $value) {
            $slotFrom = $from->modify(sprintf('+%d minutes', $index * 15));
            $references[] = new ReferenceInterval(
                $slotFrom,
                $slotFrom->modify('+15 minutes'),
                $value,
                'PLN/MWh',
            );
        }

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource([$delta]),
            new InMemoryReferenceDataSource(['PL.TGE.FIXING1' => $references]),
        ))->calculate(
            'meter',
            new TimeRange($from, $to),
            $this->definition([
                'type' => 'ACTIVE_ENERGY_IMPORT',
                'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
                'periodInMinutes' => 60,
                'allocation' => [
                    'strategy' => 'EQUAL',
                    'periodInMinutes' => 15,
                ],
            ], [
                'type' => 'REFERENCE',
                'source' => 'PL.TGE.FIXING1',
                'sourceUnit' => 'PLN/MWh',
                'multiplier' => '0.001',
            ]),
            new CalculationOptions(includeIntervals: true),
        );

        self::assertSame('0.44', $result->usageBasedTotal);
        self::assertSame('1.2', $result->usage[QuantityType::ACTIVE_ENERGY_IMPORT->value]);
        self::assertSame('0.4', $result->usage[QuantityType::ACTIVE_ENERGY_EXPORT->value]);
        self::assertCount(4, $result->charges);
        self::assertSame(['0.2', '0.2', '0.2', '0.2'], array_column(array_column($result->charges, 'quantity'), 'value'));
        self::assertSame(['0.4', '0.5', '0.6', '0.7'], array_column($result->charges, 'rate'));
        self::assertSame(['0.08', '0.1', '0.12', '0.14'], array_column($result->charges, 'cost'));

        foreach ($result->charges as $index => $charge) {
            self::assertSame('0.8', $charge['quantity']['windowValue']);
            self::assertSame('1.2', $charge['quantity']['import']);
            self::assertSame('0.4', $charge['quantity']['export']);
            self::assertSame('EQUAL', $charge['quantity']['allocation']['strategy']);
            self::assertSame(15, $charge['quantity']['allocation']['periodInMinutes']);
            self::assertSame($index, $charge['quantity']['allocation']['index']);
            self::assertSame(4, $charge['quantity']['allocation']['count']);
            self::assertSame($from->format(DATE_ATOM), $charge['quantity']['allocation']['sourceWindow']['from']);
            self::assertSame($to->format(DATE_ATOM), $charge['quantity']['allocation']['sourceWindow']['to']);
        }

        self::assertCount(1, $result->intervals);
        self::assertSame('0', $result->intervals[0]['costs']['total']);
        self::assertSame([], $result->intervals[0]['components']);
    }

    private function definition(array $quantity, ?array $rate = null): array
    {
        return [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'taxRuleSets' => [[
                'validFrom' => null,
                'validTo' => null,
                'rules' => [[
                    'id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['UNUSED'],
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
                    'selector' => ['type' => 'ALWAYS'],
                    'rate' => $rate ?? ['type' => 'CONSTANT', 'value' => '1'],
                ]],
            ]],
        ];
    }
}
