<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class AdditionalHouseholdTariffPresetTest extends TestCase
{
    public function testCatalogContainsWeekendAndAdditionalHouseholdGroups(): void
    {
        $byId = array_column((new TariffPresetCatalog())->presets(), null, 'id');
        foreach ([
            'PL.TAURON_DYSTRYBUCJA.G12w.2026',
            'PL.TAURON_SPRZEDAZ.G12w.2026',
            'PL.PGE_DYSTRYBUCJA.G12w.2026',
            'PL.PGE_OBROT.G12w.2026',
            'PL.PGE_DYSTRYBUCJA.G12n.2026',
            'PL.PGE_OBROT.G12n.2026',
            'PL.ENEA_OPERATOR.G12w.2026',
            'PL.ENEA.G12w.2026',
            'PL.ENEA_OPERATOR.G12sezON.2026',
            'PL.ENERGA_OPERATOR.G12w.2026',
            'PL.ENERGA_OBROT.G12w.2026',
            'PL.ENERGA_OPERATOR.G12r.2026',
            'PL.ENERGA_OBROT.G12r.2026',
            'PL.STOEN_OPERATOR.G12w.2026',
            'PL.EON_POLSKA.G12w.2026',
        ] as $id) {
            self::assertArrayHasKey($id, $byId);
        }
    }

    public function testTauronG12wUsesWeekendAndHolidayOffPeakZone(): void
    {
        $component = $this->component('PL.TAURON_DYSTRYBUCJA.G12w.2026', 'distribution-variable');

        self::assertSame(['DAY' => '0.3298', 'NIGHT' => '0.0512'], $component['rate']['rates']);
        self::assertSame('PL_PUBLIC_HOLIDAYS', $component['selector']['calendar']);
        self::assertSame(['HOLIDAY'], $component['selector']['rules'][0]['days']);
        self::assertSame(['SAT', 'SUN'], $component['selector']['rules'][1]['days']);
        self::assertSame('NIGHT', $component['selector']['rules'][0]['zone']);
        self::assertSame('NIGHT', $component['selector']['rules'][1]['zone']);
    }

    public function testPgeG12wKeepsSeasonalTwoHourValley(): void
    {
        $component = $this->component('PL.PGE_DYSTRYBUCJA.G12w.2026', 'distribution-variable');

        self::assertSame(['DAY' => '0.4276', 'NIGHT' => '0.0845'], $component['rate']['rates']);
        self::assertSame('SUMMER', $component['selector']['rules'][2]['season']);
        self::assertSame('15:00', $component['selector']['rules'][2]['time_ranges'][1]['from']);
        self::assertSame('WINTER', $component['selector']['rules'][4]['season']);
        self::assertSame('13:00', $component['selector']['rules'][4]['time_ranges'][1]['from']);
    }

    public function testPgeG12nMakesSundayAndHolidaysOffPeakAllDay(): void
    {
        $component = $this->component('PL.PGE_DYSTRYBUCJA.G12n.2026', 'distribution-variable');

        self::assertSame(['DAY' => '0.3470', 'NIGHT' => '0.0347'], $component['rate']['rates']);
        self::assertSame(['HOLIDAY'], $component['selector']['rules'][0]['days']);
        self::assertSame(['SUN'], $component['selector']['rules'][1]['days']);
        self::assertSame('01:00', $component['selector']['rules'][2]['time_ranges'][0]['from']);
        self::assertSame('05:00', $component['selector']['rules'][2]['time_ranges'][0]['to']);
    }

    public function testEnergaG12rAndEneaG12sezOnUseFixedSeasonSchedules(): void
    {
        $energa = $this->component('PL.ENERGA_OPERATOR.G12r.2026', 'distribution-variable');
        self::assertSame(['DAY' => '0.3640', 'NIGHT' => '0.0882'], $energa['rate']['rates']);
        self::assertSame('13:00', $energa['selector']['rules'][0]['time_ranges'][1]['from']);
        self::assertSame('16:00', $energa['selector']['rules'][0]['time_ranges'][1]['to']);

        $enea = $this->component('PL.ENEA_OPERATOR.G12sezON.2026', 'distribution-variable');
        self::assertSame(['OTHER' => '0.2779', 'RECOMMENDED_CONSUMPTION' => '0.0913'], $enea['rate']['rates']);
        self::assertSame('04:00', $enea['selector']['rules'][0]['time_ranges'][0]['from']);
        self::assertSame('22:00', $enea['selector']['rules'][2]['time_ranges'][0]['from']);
    }

    public function testNewSupplyDefaultsIncludeExciseWhereTariffSourceExcludesIt(): void
    {
        $cases = [
            'PL.TAURON_SPRZEDAZ.G12w.2026' => ['DAY' => '0.6270', 'NIGHT' => '0.4180'],
            'PL.PGE_OBROT.G12w.2026' => ['DAY' => '0.5871', 'NIGHT' => '0.4285'],
            'PL.PGE_OBROT.G12n.2026' => ['DAY' => '0.5561', 'NIGHT' => '0.3962'],
            'PL.ENEA.G12w.2026' => ['DAY' => '0.6568', 'NIGHT' => '0.3515'],
            'PL.ENERGA_OBROT.G12w.2026' => ['DAY' => '0.6107', 'NIGHT' => '0.3990'],
            'PL.ENERGA_OBROT.G12r.2026' => ['DAY' => '0.6717', 'NIGHT' => '0.3067'],
            'PL.EON_POLSKA.G12w.2026' => ['DAY' => '0.5294', 'NIGHT' => '0.4445'],
        ];
        foreach ($cases as $id => $rates) {
            self::assertSame($rates, $this->component($id, 'energy-purchase')['rate']['rates'], $id);
        }
    }

    /** @return array<string, mixed> */
    private function component(string $presetId, string $componentId): array
    {
        return (new TariffPresetCompiler())->compileComponentToArray($presetId, $componentId, [])['periods'][0]['components'][0];
    }
}
