<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class DynamicOfferPresetTest extends TestCase
{
    public function testEnergaDynamicOfferUsesFixingOneWithPublishedMarkup(): void
    {
        $compiler = new TariffPresetCompiler();
        $compiled = $compiler->compileComponentToArray(
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.2025_11',
            'energy-purchase',
            [],
        );

        $component = $compiled['periods'][0]['components'][0];
        $rate = $component['rate'];
        self::assertSame('REFERENCE', $rate['type']);
        self::assertSame('PL.TGE.FIXING1_HOURLY', $rate['source']);
        self::assertSame('PLN/MWh', $rate['sourceUnit']);
        self::assertSame('0.001', $rate['multiplier']);
        self::assertSame('0.0878', $rate['add']);
        self::assertSame('IMPORT_MINUS_EXPORT_CAP_ZERO', $component['quantity']['strategy']);
        self::assertSame(60, $component['quantity']['periodInMinutes']);

        $overridden = $compiler->compileComponentToArray(
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.2025_11',
            'energy-purchase',
            ['energy.add' => '0.0900'],
        );
        self::assertSame('0.0900', $overridden['periods'][0]['components'][0]['rate']['add']);
    }

    public function testEnergaDynamicOfferExposesSupplierFeeAsSeparateOverridableComponent(): void
    {
        $compiler = new TariffPresetCompiler();

        $default = $compiler->compileComponentToArray(
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.2025_11',
            'supplier-fixed',
            [],
        );
        self::assertSame('PERIOD', $default['periods'][0]['components'][0]['quantity']['type']);
        self::assertSame('MONTH', $default['periods'][0]['components'][0]['quantity']['period']);
        self::assertSame('8.12', $default['periods'][0]['components'][0]['rate']['value']);

        $overridden = $compiler->compileComponentToArray(
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.2025_11',
            'supplier-fixed',
            ['supplier.fixed' => '12.19'],
        );
        self::assertSame('12.19', $overridden['periods'][0]['components'][0]['rate']['value']);
    }

    public function testEneaDynamicOfferUsesPublishedFixingOneFormula(): void
    {
        $compiled = (new TariffPresetCompiler())->compileComponentToArray(
            'PL.ENEA.CENY_DYNAMICZNE.DI12011227_G',
            'energy-purchase',
            [],
        );

        $component = $compiled['periods'][0]['components'][0];
        $rate = $component['rate'];
        self::assertSame('REFERENCE', $rate['type']);
        self::assertSame('PL.TGE.FIXING1_HOURLY', $rate['source']);
        self::assertSame('0.001', $rate['multiplier']);
        self::assertSame('0.0870', $rate['add']);
        self::assertSame('IMPORT_MINUS_EXPORT_CAP_ZERO', $component['quantity']['strategy']);
        self::assertSame(60, $component['quantity']['periodInMinutes']);
    }

    public function testDynamicOfferMetadataAdvertisesBothComponents(): void
    {
        $catalog = new TariffPresetCatalog();
        $metadata = array_values(array_filter(
            $catalog->presets(),
            static fn(array $preset): bool => $preset['id'] === 'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.2025_11',
        ))[0];

        self::assertSame('OFFER', $metadata['presetType']);
        self::assertSame(['ENERGY_PURCHASE', 'SUPPLIER_FIXED'], array_column($metadata['components'], 'kind'));
        self::assertSame(['energy-purchase', 'supplier-fixed'], array_column($metadata['components'], 'componentId'));
        self::assertSame('ENERGA_OBROT', $metadata['provider']['id']);
    }
}
