<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\TariffPresetCompilationException;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class GenericTariffPresetTest extends TestCase
{
    public function testCatalogExposesGenericEnergyPresetsWithCompatibilityMetadata(): void
    {
        $presets = (new TariffPresetCatalog())->presets();
        $byId = array_column($presets, null, 'id');

        self::assertSame('GENERIC', $byId['PL.GENERIC.ENERGY_PURCHASE.CONSTANT.V1']['presetType']);
        self::assertSame('ENERGY_PURCHASE', $byId['PL.GENERIC.ENERGY_PURCHASE.CONSTANT.V1']['components'][0]['kind']);
        self::assertSame('GENERIC', $byId['PL.GENERIC.ENERGY_PURCHASE.MARKET_REFERENCE.V1']['presetType']);
    }

    public function testCompilesGenericDynamicEnergyWithSelectedReferenceAndFormula(): void
    {
        $compiled = (new TariffPresetCompiler())->compileComponentToArray(
            'PL.GENERIC.ENERGY_PURCHASE.MARKET_REFERENCE.V1',
            'energy-purchase',
            [
                'energy.source' => 'PL.PSE.RCE',
                'energy.multiplier' => '0.001',
                'energy.add' => '0.0878',
            ],
        );

        $rate = $compiled['periods'][0]['components'][0]['rate'];
        self::assertSame('REFERENCE', $rate['type']);
        self::assertSame('PL.PSE.RCE', $rate['source']);
        self::assertSame('0.001', $rate['multiplier']);
        self::assertSame('0.0878', $rate['add']);
    }

    public function testRejectsUnknownChoiceValue(): void
    {
        $this->expectException(TariffPresetCompilationException::class);
        $this->expectExceptionMessage('must be one of the declared choices');

        (new TariffPresetCompiler())->compileComponentToArray(
            'PL.GENERIC.ENERGY_PURCHASE.MARKET_REFERENCE.V1',
            'energy-purchase',
            ['energy.source' => 'PL.UNKNOWN.SOURCE'],
        );
    }
}
