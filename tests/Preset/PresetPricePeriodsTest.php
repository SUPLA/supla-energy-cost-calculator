<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class PresetPricePeriodsTest extends TestCase
{
    public function testPresetsHaveOpenEndedPriceAndCatalogValidity(): void
    {
        $catalog = new TariffPresetCatalog();
        foreach (['PL.TAURON_DYSTRYBUCJA.G11', 'PL.TAURON_DYSTRYBUCJA.G11.FIXED',
            'PL.TAURON_DYSTRYBUCJA.G12', 'PL.TAURON_DYSTRYBUCJA.G12.FIXED'] as $id) {
            $preset = $catalog->get($id);
            self::assertNull($preset->document['validFrom'], $id);
            self::assertNull($preset->document['validTo'], $id);
            $periods = (new TariffPresetCompiler($catalog))->compileToArray($preset, [])['periods'];
            self::assertNull($periods[0]['validFrom'], $id);
            self::assertNull($periods[array_key_last($periods)]['validTo'], $id);
            foreach (array_slice($periods, 1) as $index => $period) {
                self::assertSame($periods[$index]['validTo'], $period['validFrom'], $id);
            }
        }
    }

    public function testVariableRatesHaveIndependentInputs(): void
    {
        $preset = (new TariffPresetCatalog())->get('PL.TAURON_DYSTRYBUCJA.G11');
        self::assertSame(['distribution.rate.2025', 'distribution.rate.2026'], array_column($preset->document['inputs'], 'id'));
        $compiled = (new TariffPresetCompiler())->compileComponentToArray($preset, 'distribution-variable', [
            'distribution.rate.2025' => '0.3333',
        ]);
        self::assertSame('0.3333', $compiled['periods'][0]['components'][0]['rate']['value']);
        self::assertSame('0.2464', $compiled['periods'][1]['components'][0]['rate']['value']);
        self::assertSame('2026-01-01T00:00:00+01:00', $compiled['periods'][1]['validFrom']);
    }

    public function testZonedRateInputsArePerYearAndZone(): void
    {
        $compiled = (new TariffPresetCompiler())->compileComponentToArray('PL.TAURON_DYSTRYBUCJA.G12', 'distribution-variable', [
            'distribution.DAY.2025' => '0.3333',
        ]);
        self::assertSame('0.3333', $compiled['periods'][0]['components'][0]['rate']['rates']['DAY']);
        self::assertSame('0.0609', $compiled['periods'][0]['components'][0]['rate']['rates']['NIGHT']);
        self::assertSame('0.2841', $compiled['periods'][1]['components'][0]['rate']['rates']['DAY']);
    }

    public function testCapacityFeeCapturesMidyear2025Relief(): void
    {
        $compiled = (new TariffPresetCompiler())->compileComponentToArray('PL.TAURON_DYSTRYBUCJA.G11.FIXED', 'capacity-fee', [
            'capacity.rate.2025-H2' => '12.00',
        ]);
        self::assertCount(3, $compiled['periods']);
        self::assertSame('0.00', $compiled['periods'][0]['components'][0]['rate']['value']);
        self::assertSame('12.00', $compiled['periods'][1]['components'][0]['rate']['value']);
        self::assertSame('17.18', $compiled['periods'][2]['components'][0]['rate']['value']);
    }

    public function testSubscriptionFeeTurnsZeroInOctober2026(): void
    {
        $compiled = (new TariffPresetCompiler())->compileComponentToArray('PL.TAURON_DYSTRYBUCJA.G12.FIXED', 'distribution-subscription', []);
        self::assertSame('2026-10-01T00:00:00+02:00', $compiled['periods'][1]['validFrom']);
        self::assertSame('0.00', $compiled['periods'][1]['components'][0]['rate']['value']);
    }
}
