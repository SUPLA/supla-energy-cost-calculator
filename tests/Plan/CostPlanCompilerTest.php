<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;

final class CostPlanCompilerTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory !== null) {
            $this->removeDirectory($this->temporaryDirectory);
        }
    }

    public function testCompilesSinglePresetBackedPlan(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 1,
            'entries' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                'values' => [
                    'billingCycle.anchor' => '2026-01-15',
                    'energy.rate' => '0.71',
                ],
            ]],
        ]);

        self::assertSame('PLN', $compiled['currency']);
        self::assertSame('Europe/Warsaw', $compiled['timezone']);
        self::assertCount(1, $compiled['periods']);
        self::assertCount(1, $compiled['billingCycles']);
        self::assertSame('0.71', $compiled['periods'][0]['components'][0]['rate']['value']);
    }

    public function testTariffPriceChangeDoesNotCreateArtificialBillingCycleBoundary(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 1,
            'entries' => [
                [
                    'validFrom' => '2026-01-01T00:00:00+01:00',
                    'validTo' => '2026-07-01T00:00:00+02:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-01-15',
                        'energy.rate' => '0.70',
                    ],
                ],
                [
                    'validFrom' => '2026-07-01T00:00:00+02:00',
                    'validTo' => '2027-01-01T00:00:00+01:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-01-15',
                        'energy.rate' => '0.62',
                    ],
                ],
            ],
        ]);

        self::assertCount(2, $compiled['periods']);
        self::assertSame('0.70', $compiled['periods'][0]['components'][0]['rate']['value']);
        self::assertSame('0.62', $compiled['periods'][1]['components'][0]['rate']['value']);
        self::assertCount(1, $compiled['billingCycles']);
        self::assertSame('2026-01-01T00:00:00+01:00', $compiled['billingCycles'][0]['validFrom']);
        self::assertSame('2027-01-01T00:00:00+01:00', $compiled['billingCycles'][0]['validTo']);
    }

    public function testEquivalentLaterAnchorDoesNotCreateBillingCycleBoundary(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 1,
            'entries' => [
                [
                    'validFrom' => '2026-01-01T00:00:00+01:00',
                    'validTo' => '2026-07-01T00:00:00+02:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-01-15',
                        'energy.rate' => '0.70',
                    ],
                ],
                [
                    'validFrom' => '2026-07-01T00:00:00+02:00',
                    'validTo' => '2027-01-01T00:00:00+01:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-07-15',
                        'energy.rate' => '0.62',
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $compiled['billingCycles']);
        self::assertSame('2026-01-15', $compiled['billingCycles'][0]['anchor']);
    }

    public function testChangingBillingAnchorCreatesBillingCycleHistoryBoundary(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 1,
            'entries' => [
                [
                    'validFrom' => '2026-01-01T00:00:00+01:00',
                    'validTo' => '2026-07-01T00:00:00+02:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-01-15',
                        'energy.rate' => '0.70',
                    ],
                ],
                [
                    'validFrom' => '2026-07-01T00:00:00+02:00',
                    'validTo' => '2027-01-01T00:00:00+01:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-07-01',
                        'energy.rate' => '0.70',
                    ],
                ],
            ],
        ]);

        self::assertCount(2, $compiled['billingCycles']);
        self::assertSame('2026-07-01T00:00:00+02:00', $compiled['billingCycles'][0]['validTo']);
        self::assertSame('2026-07-01T00:00:00+02:00', $compiled['billingCycles'][1]['validFrom']);
    }

    public function testCanSwitchPresetWithinOnePlan(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 1,
            'entries' => [
                [
                    'validFrom' => '2026-01-01T00:00:00+01:00',
                    'validTo' => '2026-07-01T00:00:00+02:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G12.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-01-15',
                        'energy.DAY' => '0.98',
                        'energy.NIGHT' => '0.62',
                    ],
                ],
                [
                    'validFrom' => '2026-07-01T00:00:00+02:00',
                    'validTo' => '2027-01-01T00:00:00+01:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-01-15',
                        'energy.rate' => '0.71',
                    ],
                ],
            ],
        ]);

        self::assertCount(2, $compiled['periods']);
        self::assertSame('ZONED', $compiled['periods'][0]['components'][0]['rate']['type']);
        self::assertSame('CONSTANT', $compiled['periods'][1]['components'][0]['rate']['type']);
        self::assertCount(1, $compiled['billingCycles']);
    }

    public function testExistingPlanUsesCorrectedDocumentForSamePresetId(): void
    {
        $directory = $this->temporaryPresetDirectory();
        $this->writeCorrectablePreset($directory, '0.10');
        $plan = [
            'version' => 1,
            'entries' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'presetId' => 'TEST.G11.2026',
                'values' => [
                    'billingCycle.anchor' => '2026-01-15',
                    'energy.rate' => '0.70',
                ],
            ]],
        ];

        $before = (new CostPlanCompiler(new TariffPresetCatalog($directory)))->compileToArray($plan);
        self::assertSame('0.10', $before['periods'][0]['components'][1]['rate']['value']);

        // Same stable preset ID, corrected package-owned default.
        $this->writeCorrectablePreset($directory, '0.20');
        $after = (new CostPlanCompiler(new TariffPresetCatalog($directory)))->compileToArray($plan);

        self::assertSame('0.20', $after['periods'][0]['components'][1]['rate']['value']);
        self::assertSame('0.70', $after['periods'][0]['components'][0]['rate']['value']);

        $plan['entries'][0]['values']['distribution.rate'] = '0.15';
        $overridden = (new CostPlanCompiler(new TariffPresetCatalog($directory)))->compileToArray($plan);
        self::assertSame('0.15', $overridden['periods'][0]['components'][1]['rate']['value']);
    }

    public function testRejectsOverlappingEffectiveEntries(): void
    {
        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('must not overlap');

        (new CostPlanCompiler())->compile([
            'version' => 1,
            'entries' => [
                [
                    'validFrom' => '2026-01-01T00:00:00+01:00',
                    'validTo' => '2026-08-01T00:00:00+02:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-01-15',
                        'energy.rate' => '0.70',
                    ],
                ],
                [
                    'validFrom' => '2026-07-01T00:00:00+02:00',
                    'validTo' => '2027-01-01T00:00:00+01:00',
                    'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                    'values' => [
                        'billingCycle.anchor' => '2026-01-15',
                        'energy.rate' => '0.62',
                    ],
                ],
            ],
        ]);
    }

    public function testOmittedEntryRangeUsesPresetValidity(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 1,
            'entries' => [[
                'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                'values' => [
                    'billingCycle.anchor' => '2026-01-15',
                    'energy.rate' => '0.70',
                ],
            ]],
        ]);

        self::assertSame('2026-01-01T00:00:00+01:00', $compiled['periods'][0]['validFrom']);
        self::assertSame('2027-01-01T00:00:00+01:00', $compiled['periods'][0]['validTo']);
    }

    public function testExplicitEntryRangeIsIntersectedWithPresetValidity(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 1,
            'entries' => [[
                'validFrom' => '2025-01-01T00:00:00+01:00',
                'validTo' => '2028-01-01T00:00:00+01:00',
                'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                'values' => [
                    'billingCycle.anchor' => '2026-01-15',
                    'energy.rate' => '0.70',
                ],
            ]],
        ]);

        self::assertSame('2026-01-01T00:00:00+01:00', $compiled['periods'][0]['validFrom']);
        self::assertSame('2027-01-01T00:00:00+01:00', $compiled['periods'][0]['validTo']);
    }

    public function testRejectsEntryWithNoOverlapWithPresetValidity(): void
    {
        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('does not overlap tariff preset');

        (new CostPlanCompiler())->compile([
            'version' => 1,
            'entries' => [[
                'validFrom' => '2027-02-01T00:00:00+01:00',
                'validTo' => '2027-03-01T00:00:00+01:00',
                'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                'values' => [
                    'billingCycle.anchor' => '2026-01-15',
                    'energy.rate' => '0.70',
                ],
            ]],
        ]);
    }

    private function temporaryPresetDirectory(): string
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/energy-cost-plan-presets-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0777, true);
        return $this->temporaryDirectory;
    }

    private function writeCorrectablePreset(string $directory, string $distributionRate): void
    {
        file_put_contents($directory . '/index.json', json_encode([
            'version' => 1,
            'presets' => [[
                'id' => 'TEST.G11.2026',
                'label' => 'Test G11',
                'path' => 'G11.json',
            ]],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($directory . '/G11.json', json_encode([
            'version' => 1,
            'id' => 'TEST.G11.2026',
            'label' => 'Test G11',
            'validFrom' => '2026-01-01T00:00:00+01:00',
            'validTo' => '2027-01-01T00:00:00+01:00',
            'inputs' => [
                [
                    'id' => 'billingCycle.anchor',
                    'type' => 'DATE',
                    'required' => true,
                    'targets' => ['/billingCycle/anchor'],
                ],
                [
                    'id' => 'energy.rate',
                    'type' => 'DECIMAL',
                    'required' => true,
                    'targets' => ['/periods/0/components/0/rate/value'],
                ],
                [
                    'id' => 'distribution.rate',
                    'type' => 'DECIMAL',
                    'required' => true,
                    'targets' => ['/periods/0/components/1/rate/value'],
                ],
            ],
            'billingDefinitionTemplate' => [
                'version' => 1,
                'currency' => 'PLN',
                'timezone' => 'Europe/Warsaw',
                'billingCycle' => [
                    'anchor' => null,
                    'length' => 1,
                    'unit' => 'MONTH',
                ],
                'periods' => [[
                    'validFrom' => '2026-01-01T00:00:00+01:00',
                    'validTo' => '2027-01-01T00:00:00+01:00',
                    'components' => [
                        [
                            'id' => 'energy',
                            'category' => 'ENERGY',
                            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                            'rate' => ['type' => 'CONSTANT', 'value' => null, 'unit' => 'PLN/kWh'],
                        ],
                        [
                            'id' => 'distribution',
                            'category' => 'NETWORK',
                            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                            'rate' => ['type' => 'CONSTANT', 'value' => $distributionRate, 'unit' => 'PLN/kWh'],
                        ],
                    ],
                ]],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                is_dir($path) ? $this->removeDirectory($path) : unlink($path);
            }
        }
        rmdir($directory);
    }
}
