<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\TariffPresetCompilationException;
use Supla\EnergyCostCalculator\Preset\TariffPreset;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class TariffPresetCompilerTest extends TestCase
{
    public function testCompilesBundledG11UsingUserValuesAndPresetDefaults(): void
    {
        $compiled = (new TariffPresetCompiler())->compileToArray('PL.TAURON_DYSTRYBUCJA.G11.2026', [
            'billingCycle.anchor' => '2026-01-15',
            'energy.rate' => '0.71',
        ]);

        self::assertSame('2026-01-15', $compiled['billingCycle']['anchor']);
        self::assertSame(1, $compiled['billingCycle']['length']);
        self::assertSame('0.71', $compiled['periods'][0]['components'][0]['rate']['value']);
        self::assertSame('0.2464', $compiled['periods'][0]['components'][1]['rate']['value']);
    }

    public function testCompilesBundledG12WithZonedEnergyRates(): void
    {
        $compiled = (new TariffPresetCompiler())->compileToArray('PL.TAURON_DYSTRYBUCJA.G12.2026', [
            'billingCycle.anchor' => '2026-01-15',
            'billingCycle.length' => '2',
            'energy.DAY' => '0.98',
            'energy.NIGHT' => '0.62',
        ]);

        self::assertSame(2, $compiled['billingCycle']['length']);
        self::assertSame('0.98', $compiled['periods'][0]['components'][0]['rate']['rates']['DAY']);
        self::assertSame('0.62', $compiled['periods'][0]['components'][0]['rate']['rates']['NIGHT']);
    }

    public function testRejectsUnknownInput(): void
    {
        $this->expectException(TariffPresetCompilationException::class);
        $this->expectExceptionMessage("Unknown input 'unknown'");

        (new TariffPresetCompiler())->compile('PL.TAURON_DYSTRYBUCJA.G11.2026', [
            'unknown' => 'x',
        ]);
    }

    public function testCompilesOneComponentWithoutRequiringOtherInputOverrides(): void
    {
        $compiled = (new TariffPresetCompiler())->compileComponentToArray(
            'PL.ENEA_OPERATOR.G12.2026',
            'energy-purchase',
            [
                'energy.DAY' => '0.58',
                'energy.NIGHT' => '0.35',
                'schedule.nightShort.from' => '12:00',
            ],
        );

        self::assertCount(1, $compiled['periods'][0]['components']);
        self::assertSame('ENERGY', $compiled['periods'][0]['components'][0]['category']);
        self::assertSame('12:00', $compiled['periods'][0]['components'][0]['selector']['rules'][0]['time_ranges'][1]['from']);
        self::assertSame('2026-01-01', $compiled['billingCycle']['anchor']);
    }

    public function testRejectsUnresolvedRequiredInput(): void
    {
        $this->expectException(TariffPresetCompilationException::class);
        $this->expectExceptionMessage("Required input 'rate'");

        (new TariffPresetCompiler())->compile($this->customPreset('/periods/0/components/0/rate/rates/A~1B'), []);
    }

    public function testRejectsIntegerBelowPresetMinimum(): void
    {
        $this->expectException(TariffPresetCompilationException::class);
        $this->expectExceptionMessage('must be at least 1');

        (new TariffPresetCompiler())->compile('PL.TAURON_DYSTRYBUCJA.G11.2026', [
            'billingCycle.anchor' => '2026-01-15',
            'billingCycle.length' => 0,
            'energy.rate' => '0.71',
        ]);
    }

    public function testSupportsEscapedJsonPointerTokens(): void
    {
        $preset = $this->customPreset('/periods/0/components/0/rate/rates/A~1B');

        $compiled = (new TariffPresetCompiler())->compileToArray($preset, ['rate' => '0.42']);

        self::assertSame('0.42', $compiled['periods'][0]['components'][0]['rate']['rates']['A/B']);
    }

    public function testRejectsMalformedJsonPointerTarget(): void
    {
        $preset = $this->customPreset('periods/0/components/0/rate/rates/A~1B');

        $this->expectException(TariffPresetCompilationException::class);
        $this->expectExceptionMessage('is not a JSON Pointer');

        (new TariffPresetCompiler())->compileToArray($preset, ['rate' => '0.42']);
    }

    private function customPreset(string $target): TariffPreset
    {
        $document = [
            'version' => 1,
            'id' => 'TEST.G11.2026',
            'inputs' => [[
                'id' => 'rate',
                'type' => 'DECIMAL',
                'required' => true,
                'targets' => [$target],
            ]],
            'billingDefinitionTemplate' => [
                'version' => 1,
                'currency' => 'PLN',
                'timezone' => 'Europe/Warsaw',
                'billingCycle' => ['anchor' => null, 'length' => 1, 'unit' => 'MONTH'],
                'periods' => [[
                    'validFrom' => '2026-01-01T00:00:00+01:00',
                    'validTo' => '2027-01-01T00:00:00+01:00',
                    'components' => [[
                        'id' => 'energy',
                        'category' => 'ENERGY',
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'rate' => [
                            'type' => 'ZONED',
                            'rates' => ['A/B' => null],
                            'unit' => 'PLN/kWh',
                        ],
                    ]],
                ]],
            ],
        ];

        // Anchor is not an input in this synthetic preset; make it valid for BillingDefinitionParser.
        $document['billingDefinitionTemplate']['billingCycle']['anchor'] = '2026-01-01';

        return new TariffPreset('TEST.G11.2026', str_repeat('0', 64), [], $document);
    }
}
