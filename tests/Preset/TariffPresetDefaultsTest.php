<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class TariffPresetDefaultsTest extends TestCase
{
    public function testEveryBundledPresetCanBeCompiledWithoutInput(): void
    {
        $catalog = new TariffPresetCatalog();
        $compiler = new TariffPresetCompiler($catalog);

        foreach ($catalog->presets() as $metadata) {
            $preset = $catalog->get($metadata['id']);
            foreach ($preset->document['inputs'] as $input) {
                if (($input['required'] ?? false) !== true) {
                    continue;
                }
                $templateValue = $this->readPointer($preset->document['billingDefinitionTemplate'], $input['targets'][0]);
                self::assertNotTrue(
                    $templateValue === null || $templateValue === '',
                    "$preset->id required input '{$input['id']}' is unresolved",
                );
            }

            $definition = $compiler->compile($preset, []);
            self::assertSame($preset->document['currency'], $definition->currency, $preset->id);
            self::assertSame($preset->document['timezone'], $definition->timezone, $preset->id);
        }
    }

    #[DataProvider('energyPriceDefaults')]
    public function testBundledEnergyPurchaseDefaults(string $presetId, string $supplierId, array $expectedValues): void
    {
        $preset = (new TariffPresetCatalog())->get($presetId);
        $energyPurchase = $preset->document['energyPurchase'];

        self::assertSame($supplierId, $energyPurchase['supplier']['id']);
        foreach ($expectedValues as $inputId => $expectedValue) {
            foreach ($preset->document['inputs'] as $input) {
                if ($input['id'] === $inputId) {
                    self::assertSame($expectedValue, $this->readPointer($preset->document['billingDefinitionTemplate'], $input['targets'][0]), "$presetId $inputId");
                    continue 2;
                }
            }
            self::fail("$presetId missing input $inputId");
        }
    }

    public function testDefaultsMayBeOverriddenByInputs(): void
    {
        $catalog = new TariffPresetCatalog();
        $preset = $catalog->get('PL.TAURON_DYSTRYBUCJA.G11.2026');

        self::assertSame('0.5020', $this->readPointer(
            $preset->document['billingDefinitionTemplate'],
            '/periods/0/components/0/rate/value',
        ));
        $compiled = (new TariffPresetCompiler($catalog))->compileToArray($preset, ['energy.rate' => '0.6000']);
        self::assertSame('0.6000', $compiled['periods'][0]['components'][0]['rate']['value']);
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
