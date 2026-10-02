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
        $preset = $catalog->get('PL.TAURON_DYSTRYBUCJA.G11.FIXED.2026');

        self::assertSame(
            ['distribution-fixed-network', 'capacity-fee', 'distribution-subscription'],
            array_column($preset->document['components'], 'componentId'),
        );

        $compiled = (new TariffPresetCompiler($catalog))->compileToArray($preset, [
            'distribution.fixed.rate' => '10.86',
            'capacity.rate' => '24.05',
            'distribution.subscription.rate' => '2.28',
        ]);

        self::assertCount(2, $compiled['periods']);
        self::assertSame('10.86', $compiled['periods'][0]['components'][0]['rate']['value']);
        self::assertSame('10.86', $compiled['periods'][1]['components'][0]['rate']['value']);
        self::assertSame('24.05', $compiled['periods'][0]['components'][1]['rate']['value']);
        self::assertSame('24.05', $compiled['periods'][1]['components'][1]['rate']['value']);
        self::assertSame('2.28', $compiled['periods'][0]['components'][2]['rate']['value']);
        self::assertSame('0.00', $compiled['periods'][1]['components'][2]['rate']['value']);
    }

    public function testStarterReferencesFixedComponentsWithoutOverrides(): void
    {
        $starter = (new CostPlanStarterCatalog())->get('PL.STARTER.TAURON_DYSTRYBUCJA.G11');
        $byId = array_column($starter->components, null, 'componentId');

        foreach (['distribution-fixed-network', 'capacity-fee', 'distribution-subscription'] as $componentId) {
            self::assertSame('PL.TAURON_DYSTRYBUCJA.G11.FIXED.2026', $byId[$componentId]['presetId']);
            self::assertSame([], $byId[$componentId]['values']);
        }
    }
}
