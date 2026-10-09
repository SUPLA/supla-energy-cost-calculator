<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class HistoricalPriceCalculationTest extends TestCase
{
    public function testHistoricalRatesAndTariffSwitchPreserveIndependentOverrides(): void
    {
        $definition = (new CostPlanCompiler())->compile([
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycles' => [['anchor' => '2026-01-01', 'length' => 1, 'unit' => 'MONTH']],
            'periods' => [
                [
                    'validFrom' => null,
                    'validTo' => '2026-05-01T00:00:00+02:00',
                    'components' => [[
                        'kind' => 'DISTRIBUTION_VARIABLE',
                        'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11',
                        'componentId' => 'distribution-variable',
                        'values' => ['distribution.rate.2026' => '0.3000'],
                    ]],
                ],
                [
                    'validFrom' => '2026-05-01T00:00:00+02:00',
                    'validTo' => null,
                    'components' => [[
                        'kind' => 'DISTRIBUTION_VARIABLE',
                        'presetId' => 'PL.TAURON_DYSTRYBUCJA.G12',
                        'componentId' => 'distribution-variable',
                        'values' => ['distribution.DAY.2026' => '0.4000'],
                    ]],
                ],
            ],
        ]);

        // Same component identity is backed by G11 before May and G12 after May.
        // Both presets keep their own price histories; a 2026 override must not
        // change a 2025 price or the other zone of G12.
        $cases = [
            ['2025-12-15T10:00:00+01:00', '0.2541'],
            ['2026-02-15T10:00:00+01:00', '0.3000'],
            ['2026-06-15T10:00:00+02:00', '0.4000'],
            ['2026-06-15T23:00:00+02:00', '0.0558'],
        ];
        foreach ($cases as [$from, $rate]) {
            $start = new \DateTimeImmutable($from);
            $end = $start->modify('+1 hour');
            $delta = new EnergyDelta($start, $end, [
                QuantityType::ACTIVE_ENERGY_IMPORT->value => '1',
                QuantityType::ACTIVE_ENERGY_EXPORT->value => '0',
            ]);
            $result = (new CostCalculator(
                new InMemoryEnergyDeltaSource([$delta]),
                new InMemoryReferenceDataSource(),
            ))->calculate('meter', new TimeRange($start, $end), $definition, new CalculationOptions(includeCharges: true));

            self::assertCount(1, $result->charges, $from);
            self::assertSame($rate, $result->charges[0]['pricing']['rate'], $from);
        }
    }

    public function testNonProratedHistoricalFixedFeeUsesPriceAtCycleStart(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycles' => [['anchor' => '2026-09-15', 'length' => 1, 'unit' => 'MONTH']],
            'periods' => [[
                'validFrom' => null,
                'validTo' => null,
                'components' => [[
                    'kind' => 'DISTRIBUTION_FIXED',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.FIXED',
                    'componentId' => 'distribution-subscription',
                    'values' => ['distribution.subscription.rate.2026-OCT-DEC' => '8.00'],
                ]],
            ]],
        ]);

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource([]),
            new InMemoryReferenceDataSource(),
        ))->calculate('meter', new TimeRange(
            new \DateTimeImmutable('2026-09-15T00:00:00+02:00'),
            new \DateTimeImmutable('2026-11-15T00:00:00+01:00'),
        ), $compiled);

        // The October 1 rate change must not re-charge the September 15
        // invoice bucket. The new 8.00 rate applies from the October 15 bucket.
        self::assertSame('12.56', $result->costs['net']['periodic']['total']);
        self::assertCount(2, $result->periodicCharges);
        self::assertSame('1', $result->periodicCharges[0]['calculated']['units']);
        self::assertSame('1', $result->periodicCharges[1]['calculated']['units']);
        self::assertSame('4.56', $result->periodicCharges[0]['calculated']['amounts']['net']);
        self::assertSame('8', $result->periodicCharges[1]['calculated']['amounts']['net']);
    }
}
