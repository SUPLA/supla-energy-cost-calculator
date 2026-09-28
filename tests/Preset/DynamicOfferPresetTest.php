<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class DynamicOfferPresetTest extends TestCase
{
    public function testEnergaDynamicOfferHasSeparateConsumerAndProsumerSemantics(): void
    {
        $compiler = new TariffPresetCompiler();

        $consumer = $compiler->compileComponentToArray(
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.KONSUMENT.2025_11',
            'energy-purchase',
            [],
        )['periods'][0]['components'][0];
        self::assertSame('PL.TGE.FIXING1', $consumer['rate']['source']);
        self::assertSame('0.0878', $consumer['rate']['add']);
        self::assertArrayNotHasKey('strategy', $consumer['quantity']);

        $prosumer = $compiler->compileComponentToArray(
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.PROSUMENT.2025_11',
            'energy-purchase',
            [],
        )['periods'][0]['components'][0];
        self::assertSame('PL.TGE.FIXING1', $prosumer['rate']['source']);
        self::assertSame('IMPORT_MINUS_EXPORT_CAP_ZERO', $prosumer['quantity']['strategy']);
        self::assertSame(60, $prosumer['quantity']['periodInMinutes']);
        self::assertSame(['strategy' => 'EQUAL', 'periodInMinutes' => 15], $prosumer['quantity']['allocation']);

        $overridden = $compiler->compileComponentToArray(
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.PROSUMENT.2025_11',
            'energy-purchase',
            ['energy.add' => '0.0900'],
        );
        self::assertSame('0.0900', $overridden['periods'][0]['components'][0]['rate']['add']);
    }

    public function testEnergaDynamicOfferExposesSupplierFeeForBothRoles(): void
    {
        $compiler = new TariffPresetCompiler();
        foreach ([
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.KONSUMENT.2025_11',
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.PROSUMENT.2025_11',
        ] as $presetId) {
            $default = $compiler->compileComponentToArray($presetId, 'supplier-fixed', []);
            self::assertSame('PERIOD', $default['periods'][0]['components'][0]['quantity']['type']);
            self::assertSame('MONTH', $default['periods'][0]['components'][0]['quantity']['period']);
            self::assertSame('8.12', $default['periods'][0]['components'][0]['rate']['value']);
        }
    }

    public function testEneaDynamicOfferHasSeparateConsumerAndProsumerSemantics(): void
    {
        $compiler = new TariffPresetCompiler();

        $consumer = $compiler->compileComponentToArray(
            'PL.ENEA.CENY_DYNAMICZNE.DI12011227_G.KONSUMENT',
            'energy-purchase',
            [],
        )['periods'][0]['components'][0];
        self::assertSame('PL.TGE.FIXING1', $consumer['rate']['source']);
        self::assertSame('0.0870', $consumer['rate']['add']);
        self::assertArrayNotHasKey('strategy', $consumer['quantity']);

        $prosumer = $compiler->compileComponentToArray(
            'PL.ENEA.CENY_DYNAMICZNE.DI12011227_G.PROSUMENT',
            'energy-purchase',
            [],
        )['periods'][0]['components'][0];
        self::assertSame('PL.TGE.FIXING1', $prosumer['rate']['source']);
        self::assertSame('IMPORT_MINUS_EXPORT_CAP_ZERO', $prosumer['quantity']['strategy']);
        self::assertSame(60, $prosumer['quantity']['periodInMinutes']);
        self::assertSame(['strategy' => 'EQUAL', 'periodInMinutes' => 15], $prosumer['quantity']['allocation']);
    }

    public function testPgeDynamicConsumerOfferUsesPublishedClampAndNaturalReferenceResolution(): void
    {
        $compiler = new TariffPresetCompiler();
        $energy = $compiler->compileComponentToArray(
            'PL.PGE_OBROT.DYNAMICZNA_ENERGIA.G1X.KONSUMENT.2026',
            'energy-purchase',
            [],
        );
        $component = $energy['periods'][0]['components'][0];

        self::assertSame('PL.TGE.FIXING1', $component['rate']['source']);
        self::assertSame('PLN/MWh', $component['rate']['sourceUnit']);
        self::assertSame('0', $component['rate']['sourceMin']);
        self::assertSame('4000', $component['rate']['sourceMax']);
        self::assertSame('0.001', $component['rate']['multiplier']);
        self::assertSame('0.0905', $component['rate']['add']);
        self::assertArrayNotHasKey('strategy', $component['quantity']);

        $fee = $compiler->compileComponentToArray(
            'PL.PGE_OBROT.DYNAMICZNA_ENERGIA.G1X.KONSUMENT.2026',
            'supplier-fixed',
            [],
        );
        self::assertSame('22.00', $fee['periods'][0]['components'][0]['rate']['value']);
    }

    public function testPgeDynamicProsumerUsesHourlyNettingEqualAllocationAndPublishedProsumerFees(): void
    {
        $compiler = new TariffPresetCompiler();
        $energy = $compiler->compileComponentToArray(
            'PL.PGE_OBROT.DYNAMICZNA_ENERGIA.G1X.PROSUMENT.2026',
            'energy-purchase',
            [],
        );
        $component = $energy['periods'][0]['components'][0];

        self::assertSame('PL.TGE.FIXING1', $component['rate']['source']);
        self::assertSame('0', $component['rate']['sourceMin']);
        self::assertSame('4000', $component['rate']['sourceMax']);
        self::assertSame('0.1200', $component['rate']['add']);
        self::assertSame('IMPORT_MINUS_EXPORT_CAP_ZERO', $component['quantity']['strategy']);
        self::assertSame(60, $component['quantity']['periodInMinutes']);
        self::assertSame(['strategy' => 'EQUAL', 'periodInMinutes' => 15], $component['quantity']['allocation']);

        $fee = $compiler->compileComponentToArray(
            'PL.PGE_OBROT.DYNAMICZNA_ENERGIA.G1X.PROSUMENT.2026',
            'supplier-fixed',
            [],
        );
        self::assertSame('27.00', $fee['periods'][0]['components'][0]['rate']['value']);
    }

    public function testDynamicOfferMetadataAdvertisesRoleSpecificEnergaPresets(): void
    {
        $catalog = new TariffPresetCatalog();
        $byId = array_column($catalog->presets(), null, 'id');

        foreach ([
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.KONSUMENT.2025_11',
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.PROSUMENT.2025_11',
        ] as $id) {
            self::assertArrayHasKey($id, $byId);
            self::assertSame('OFFER', $byId[$id]['presetType']);
            self::assertSame(['ENERGY_PURCHASE', 'SUPPLIER_FIXED'], array_column($byId[$id]['components'], 'kind'));
            self::assertSame('ENERGA_OBROT', $byId[$id]['provider']['id']);
        }
    }
}
