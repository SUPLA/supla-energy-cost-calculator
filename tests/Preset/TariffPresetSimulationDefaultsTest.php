<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\TariffPresetCompilationException;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class TariffPresetSimulationDefaultsTest extends TestCase
{
    public function testEveryBundledPresetCanBeCompiledUsingSimulationDefaultsWithoutAdditionalInput(): void
    {
        $catalog = new TariffPresetCatalog();
        $compiler = new TariffPresetCompiler($catalog);

        foreach ($catalog->presets() as $metadata) {
            $preset = $catalog->get($metadata['id']);
            $simulation = $preset->document['simulationDefaults'] ?? null;

            self::assertIsArray($simulation, $preset->id);
            self::assertSame('STANDARD_SUPPLIER_TARIFF', $simulation['basis'] ?? null, $preset->id);
            self::assertSame('NET_WITH_EXCISE', $simulation['energyPriceBasis'] ?? null, $preset->id);
            self::assertIsArray($simulation['supplier'] ?? null, $preset->id);
            self::assertNotSame('', $simulation['supplier']['id'] ?? '', $preset->id);
            self::assertNotSame('', $simulation['supplier']['label'] ?? '', $preset->id);
            self::assertIsArray($simulation['values'] ?? null, $preset->id);
            self::assertNotEmpty($simulation['sources'] ?? [], $preset->id);
            self::assertSame(substr($preset->document['validFrom'], 0, 10), $simulation['values']['billingCycle.anchor'] ?? null, $preset->id);

            $inputs = [];
            foreach ($preset->document['inputs'] as $input) {
                $inputs[$input['id']] = $input;
            }

            foreach ($simulation['values'] as $inputId => $_value) {
                self::assertArrayHasKey($inputId, $inputs, "$preset->id simulation default must reference a declared input");
                $target = $inputs[$inputId]['targets'][0];
                self::assertNull(
                    $this->readPointer($preset->document['billingDefinitionTemplate'], $target),
                    "$preset->id simulation default '$inputId' must not duplicate an inherited preset default",
                );
            }

            foreach ($preset->document['inputs'] as $input) {
                if (($input['required'] ?? false) !== true) {
                    continue;
                }
                $templateValue = $this->readPointer($preset->document['billingDefinitionTemplate'], $input['targets'][0]);
                $simulationValueExists = array_key_exists($input['id'], $simulation['values']);
                self::assertTrue(
                    ($templateValue !== null && $templateValue !== '') || $simulationValueExists,
                    "$preset->id required input '{$input['id']}' is unresolved even for simulation",
                );
            }

            $definition = $compiler->compile($preset, $simulation['values']);
            self::assertSame($preset->document['currency'], $definition->currency, $preset->id);
            self::assertSame($preset->document['timezone'], $definition->timezone, $preset->id);
        }
    }

    #[DataProvider('energyPriceDefaults')]
    public function testBundledEnergyPriceSuggestions(string $presetId, string $supplierId, array $expectedValues): void
    {
        $preset = (new TariffPresetCatalog())->get($presetId);
        $simulation = $preset->document['simulationDefaults'];

        self::assertSame($supplierId, $simulation['supplier']['id']);
        foreach ($expectedValues as $inputId => $expectedValue) {
            self::assertSame($expectedValue, $simulation['values'][$inputId] ?? null, "$presetId $inputId");
        }
    }

    public function testSimulationDefaultsDoNotBecomeNormalPresetDefaults(): void
    {
        $catalog = new TariffPresetCatalog();
        $preset = $catalog->get('PL.TAURON_DYSTRYBUCJA.G11.2026');

        self::assertNull($this->readPointer(
            $preset->document['billingDefinitionTemplate'],
            '/periods/0/components/0/rate/value',
        ));
        self::assertSame('0.5020', $preset->document['simulationDefaults']['values']['energy.rate']);

        $this->expectException(TariffPresetCompilationException::class);
        (new TariffPresetCompiler($catalog))->compile($preset, []);
    }

    public static function energyPriceDefaults(): iterable
    {
        yield 'TAURON G11' => ['PL.TAURON_DYSTRYBUCJA.G11.2026', 'TAURON_SPRZEDAZ', ['energy.rate' => '0.5020']];
        yield 'TAURON G12' => ['PL.TAURON_DYSTRYBUCJA.G12.2026', 'TAURON_SPRZEDAZ', ['energy.DAY' => '0.5480', 'energy.NIGHT' => '0.4180']];
        yield 'TAURON G13' => ['PL.TAURON_DYSTRYBUCJA.G13.2026', 'TAURON_SPRZEDAZ', [
            'energy.MORNING_PEAK' => '0.4718',
            'energy.AFTERNOON_PEAK' => '0.7830',
            'energy.OFF_PEAK' => '0.4260',
        ]];
        yield 'TAURON G14dynamic' => ['PL.TAURON_DYSTRYBUCJA.G14dynamic.2026', 'TAURON_SPRZEDAZ', ['energy.rate' => '0.5020']];
        yield 'PGE G11' => ['PL.PGE_DYSTRYBUCJA.G11.2026', 'PGE_OBROT', ['energy.rate' => '0.5032']];
        yield 'PGE G12' => ['PL.PGE_DYSTRYBUCJA.G12.2026', 'PGE_OBROT', ['energy.DAY' => '0.5706', 'energy.NIGHT' => '0.3768']];
        yield 'ENEA G11' => ['PL.ENEA_OPERATOR.G11.2026', 'ENEA', ['energy.rate' => '0.5030']];
        yield 'ENEA G12' => ['PL.ENEA_OPERATOR.G12.2026', 'ENEA', ['energy.DAY' => '0.5829', 'energy.NIGHT' => '0.3419']];
        yield 'ENEA G13active' => ['PL.ENEA_OPERATOR.G13active.2026', 'ENEA', [
            'energy.RECOMMENDED_LIMITATION' => '0.5030',
            'energy.OTHER' => '0.5030',
            'energy.RECOMMENDED_CONSUMPTION' => '0.5030',
        ]];
        yield 'Energa G11' => ['PL.ENERGA_OPERATOR.G11.2026', 'ENERGA_OBROT', ['energy.rate' => '0.5018']];
        yield 'Energa G12' => ['PL.ENERGA_OPERATOR.G12.2026', 'ENERGA_OBROT', ['energy.DAY' => '0.5839', 'energy.NIGHT' => '0.3803']];
        yield 'Stoen G11 / E.ON' => ['PL.STOEN_OPERATOR.G11.2026', 'EON_POLSKA', ['energy.rate' => '0.5050']];
        yield 'Stoen G12 / E.ON' => ['PL.STOEN_OPERATOR.G12.2026', 'EON_POLSKA', ['energy.DAY' => '0.5394', 'energy.NIGHT' => '0.4295']];
    }

    /** @param array<string|int, mixed> $document */
    private function readPointer(array $document, string $pointer): mixed
    {
        self::assertStringStartsWith('/', $pointer);
        $cursor = $document;
        foreach (explode('/', substr($pointer, 1)) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
            $key = array_is_list($cursor) ? (int)$token : $token;
            self::assertArrayHasKey($key, $cursor, $pointer);
            $cursor = $cursor[$key];
        }
        return $cursor;
    }
}
