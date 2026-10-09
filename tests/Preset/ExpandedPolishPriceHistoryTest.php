<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class ExpandedPolishPriceHistoryTest extends TestCase
{
    /** @return list<string> */
    private function expandedIds(): array
    {
        $fixed = [
            'ENEA_OPERATOR' => ['G11', 'G12', 'G12sezON', 'G12w', 'G13active'],
            'ENERGA_OPERATOR' => ['G11', 'G12', 'G12r', 'G12w'],
            'PGE_DYSTRYBUCJA' => ['G11', 'G12', 'G12n', 'G12w'],
            'STOEN_OPERATOR' => ['G11', 'G12', 'G12w'],
            'TAURON_DYSTRYBUCJA' => ['G12w', 'G13', 'G14dynamic'],
        ];
        $supply = [
            'ENEA' => ['G11', 'G12', 'G12w'],
            'ENERGA_OBROT' => ['G11', 'G12', 'G12r', 'G12w'],
            'PGE_OBROT' => ['G11', 'G12', 'G12n', 'G12w'],
        ];
        $ids = [];
        foreach ($fixed as $operator => $groups) {
            foreach ($groups as $group) {
                $ids[] = "PL.$operator.$group.FIXED";
            }
        }
        foreach ($supply as $supplier => $groups) {
            foreach ($groups as $group) {
                $ids[] = "PL.$supplier.$group";
            }
        }
        return $ids;
    }

    public function testExpandedPresetsCompileAndHaveIndependentPriceInputs(): void
    {
        $catalog = new TariffPresetCatalog();
        $compiler = new TariffPresetCompiler($catalog);
        self::assertCount(30, $this->expandedIds());
        foreach ($this->expandedIds() as $id) {
            $preset = $catalog->get($id);
            $periods = $compiler->compileToArray($preset, [])['periods'];
            self::assertGreaterThan(1, count($periods), $id);
            self::assertNull($periods[0]['validFrom'], $id);
            self::assertNull($periods[array_key_last($periods)]['validTo'], $id);
            foreach (array_slice($periods, 1) as $index => $period) {
                self::assertSame($periods[$index]['validTo'], $period['validFrom'], $id);
            }

            $expectedPricePointers = [];
            foreach ($periods as $periodIndex => $period) {
                foreach ($period['components'] as $componentIndex => $component) {
                    $base = "/periods/$periodIndex/components/$componentIndex/rate";
                    $rate = $component['rate'];
                    if ($rate['type'] === 'CONSTANT') {
                        $expectedPricePointers[] = "$base/value";
                    } elseif ($rate['type'] === 'ZONED') {
                        foreach (array_keys($rate['rates']) as $zone) {
                            $expectedPricePointers[] = "$base/rates/$zone";
                        }
                    }
                }
            }
            $actualPricePointers = [];
            $seenIds = [];
            foreach ($preset->document['inputs'] as $input) {
                self::assertArrayNotHasKey($input['id'], $seenIds, $id);
                $seenIds[$input['id']] = true;
                foreach ($input['targets'] as $target) {
                    $pointer = is_array($target) ? $target['pointer'] : $target;
                    if (str_contains($pointer, '/rate/')) {
                        self::assertCount(1, $input['targets'], "$id / {$input['id']}");
                        self::assertNotContains($pointer, $actualPricePointers, $id);
                        $actualPricePointers[] = $pointer;
                    }
                }
            }
            sort($expectedPricePointers);
            sort($actualPricePointers);
            self::assertSame($expectedPricePointers, $actualPricePointers, $id);
        }
    }

    public function testPgeG11RateOverridesAffectOnlyOnePeriod(): void
    {
        $compiled = (new TariffPresetCompiler())->compileToArray('PL.PGE_OBROT.G11', [
            'energy.rate.2025-Q4' => '0.2222',
        ]);
        self::assertSame('0.6338', $compiled['periods'][0]['components'][0]['rate']['value']);
        self::assertSame('0.2222', $compiled['periods'][1]['components'][0]['rate']['value']);
        self::assertSame('0.5032', $compiled['periods'][2]['components'][0]['rate']['value']);
    }

    public function testCapacityFee2025MidyearChangeWithoutChangingOtherPeriods(): void
    {
        $compiled = (new TariffPresetCompiler())->compileComponentToArray('PL.ENERGA_OPERATOR.G12.FIXED', 'capacity-fee', [
            'capacity.rate.2025-H2' => '12.00',
        ]);
        self::assertSame(['10.64', '0.00', '12.00', '17.18', '17.18'],
            array_map(static fn(array $period): string => $period['components'][0]['rate']['value'], $compiled['periods']));
        self::assertSame('2025-07-01T00:00:00+02:00', $compiled['periods'][2]['validFrom']);
    }

    public function testEneaZonedRatesAllowIndependentOverrideForOneZoneAndPeriod(): void
    {
        $compiled = (new TariffPresetCompiler())->compileToArray('PL.ENEA.G12', [
            'energy.NIGHT.2025-Q4' => '0.4000',
        ]);
        self::assertSame('0.7556', $compiled['periods'][0]['components'][0]['rate']['rates']['DAY']);
        self::assertSame('0.4106', $compiled['periods'][0]['components'][0]['rate']['rates']['NIGHT']);
        self::assertSame('0.6865', $compiled['periods'][1]['components'][0]['rate']['rates']['DAY']);
        self::assertSame('0.4000', $compiled['periods'][1]['components'][0]['rate']['rates']['NIGHT']);
        self::assertSame('0.5030', (new TariffPresetCompiler())->compileToArray('PL.ENEA.G11', [])['periods'][2]['components'][0]['rate']['value']);
    }
}
