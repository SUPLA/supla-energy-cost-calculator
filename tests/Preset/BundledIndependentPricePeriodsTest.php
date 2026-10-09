<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;
use Supla\EnergyCostCalculator\Preset\TariffPresetPricePeriodMigrator;

final class BundledIndependentPricePeriodsTest extends TestCase
{
    public function testEnergaG11HasOnlySevenIndependentFixedPriceInputs(): void
    {
        $preset = (new TariffPresetCatalog())->get('PL.ENERGA_OPERATOR.G11.FIXED');
        $template = $preset->document['billingDefinitionTemplate'];
        self::assertArrayNotHasKey('periods', $template);
        self::assertSame(['distribution-fixed-network', 'capacity-fee', 'distribution-subscription'],
            array_column($template['components'], 'id'));
        self::assertSame([1, 4, 2], array_map(static fn(array $component): int => count($component['pricePeriods']), $template['components']));
        self::assertCount(7, $preset->document['inputs']);

        $compiled = (new TariffPresetCompiler())->compileComponentToArray($preset, 'distribution-fixed-network', [
            'distribution.fixed.rate' => '8.50',
        ]);
        self::assertCount(1, $compiled['periods']);
        self::assertNull($compiled['periods'][0]['validFrom']);
        self::assertNull($compiled['periods'][0]['validTo']);
        self::assertSame('8.50', $compiled['periods'][0]['components'][0]['rate']['value']);
    }

    public function testMigrationRejectsNonPriceStructuralChanges(): void
    {
        $component = static fn(string $period): array => [
            'id' => 'fixed',
            'kind' => 'DISTRIBUTION_FIXED',
            'category' => 'NETWORK',
            'quantity' => ['type' => 'PERIOD', 'period' => $period],
            'taxTreatment' => ['included' => []],
            'rate' => ['type' => 'CONSTANT', 'value' => '7.83', 'unit' => 'PLN/month'],
        ];
        $document = [
            'inputs' => [],
            'billingDefinitionTemplate' => ['periods' => [
                ['validFrom' => null, 'validTo' => '2026-01-01T00:00:00+01:00', 'components' => [$component('MONTH')]],
                ['validFrom' => '2026-01-01T00:00:00+01:00', 'validTo' => null, 'components' => [$component('YEAR')]],
            ]],
        ];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('changes non-price fields');
        (new TariffPresetPricePeriodMigrator())->convert($document);
    }

    public function testPreservedZonedRateFieldsCanShareOneInputAcrossPricePeriods(): void
    {
        $component = static fn(string $day, string $night): array => [
            'id' => 'energy-purchase',
            'kind' => 'ENERGY_PURCHASE',
            'category' => 'ENERGY',
            'taxTreatment' => ['included' => []],
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'selector' => ['type' => 'ALWAYS'],
            'rate' => ['type' => 'ZONED', 'rates' => ['DAY' => $day, 'NIGHT' => $night], 'unit' => 'PLN/kWh'],
        ];
        $times = [null, '2025-01-01T00:00:00+01:00', '2026-01-01T00:00:00+01:00', null];
        $rates = [['0.30', '0.10'], ['0.30', '0.20'], ['0.40', '0.20']];
        $periods = [];
        $inputs = [];
        foreach ($rates as $index => [$day, $night]) {
            $periods[] = [
                'validFrom' => $times[$index],
                'validTo' => $times[$index + 1],
                'components' => [$component($day, $night)],
            ];
            foreach (['DAY', 'NIGHT'] as $zone) {
                $inputs[] = [
                    'id' => "price.$zone.$index", 'label' => "Cena $zone", 'type' => 'DECIMAL', 'required' => true,
                    'targets' => ["/periods/$index/components/0/rate/rates/$zone"],
                    'unit' => 'PLN/kWh',
                ];
            }
        }
        $document = ['inputs' => $inputs, 'billingDefinitionTemplate' => ['version' => 1, 'periods' => $periods]];
        $converted = (new TariffPresetPricePeriodMigrator())->convert($document);
        self::assertCount(3, $converted['billingDefinitionTemplate']['components'][0]['pricePeriods']);
        self::assertCount(4, $converted['inputs']);
        self::assertSame(['/components/0/pricePeriods/0/rate/rates/DAY', '/components/0/pricePeriods/1/rate/rates/DAY'],
            $converted['inputs'][0]['targets']);
        self::assertSame(['/components/0/pricePeriods/1/rate/rates/NIGHT', '/components/0/pricePeriods/2/rate/rates/NIGHT'],
            $converted['inputs'][2]['targets']);
        self::assertSame($converted, (new TariffPresetPricePeriodMigrator())->convert($converted));
    }
}
