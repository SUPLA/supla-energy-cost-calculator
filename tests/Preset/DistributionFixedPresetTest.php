<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Plan\CostPlanStarterCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class DistributionFixedPresetTest extends TestCase
{
    public function testTauronG11DefaultsAndOverrides(): void
    {
        $catalog = new TariffPresetCatalog();
        $preset = $catalog->get('PL.TAURON_DYSTRYBUCJA.G11.FIXED');

        self::assertSame(
            ['distribution-fixed-network', 'capacity-fee', 'distribution-subscription'],
            array_column($preset->document['components'], 'componentId'),
        );

        $compiled = (new TariffPresetCompiler($catalog))->compileToArray($preset, [
            'distribution.fixed.rate.2026-JAN-SEP' => '10.86',
            'capacity.rate.2026-JAN-SEP' => '24.05',
            'distribution.subscription.rate.2026-JAN-SEP' => '2.28',
            'distribution.subscription.rate.2026-OCT-DEC' => '0.00',
        ]);

        self::assertCount(4, $compiled['periods']);
        self::assertSame('7.02', $compiled['periods'][0]['components'][0]['rate']['value']);
        self::assertSame('11.44', $compiled['periods'][1]['components'][1]['rate']['value']);
        self::assertSame('10.86', $compiled['periods'][2]['components'][0]['rate']['value']);
        self::assertSame('10.86', $compiled['periods'][3]['components'][0]['rate']['value']);
        self::assertSame('24.05', $compiled['periods'][2]['components'][1]['rate']['value']);
        self::assertSame('24.05', $compiled['periods'][3]['components'][1]['rate']['value']);
        self::assertSame('2.28', $compiled['periods'][2]['components'][2]['rate']['value']);
        self::assertSame('0.00', $compiled['periods'][3]['components'][2]['rate']['value']);
    }

    public function testStarterReferencesFixedComponentsWithoutOverrides(): void
    {
        $starter = (new CostPlanStarterCatalog())->get('PL.STARTER.TAURON_DYSTRYBUCJA.G11');
        $byId = array_column($starter->components, null, 'componentId');

        foreach (['distribution-fixed-network', 'capacity-fee', 'distribution-subscription'] as $componentId) {
            self::assertSame('PL.TAURON_DYSTRYBUCJA.G11.FIXED', $byId[$componentId]['presetId']);
            self::assertSame([], $byId[$componentId]['values']);
        }
    }
}
