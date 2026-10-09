<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;

final class CostPlanTaxContextTest extends TestCase
{
    private string $presetDirectory;

    protected function setUp(): void
    {
        $this->presetDirectory = sys_get_temp_dir() . '/energy-cost-tax-context-presets-' . bin2hex(random_bytes(8));
        mkdir($this->presetDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->presetDirectory);
    }

    public function testExplicitMatchingCostPlanTaxContextWorks(): void
    {
        $plan = $this->normalPlan();
        $plan['taxContext'] = ['jurisdiction' => 'PL', 'customerClass' => 'HOUSEHOLD'];

        $compiled = (new CostPlanCompiler())->compileToArray($plan);

        self::assertCount(3, $compiled['taxRuleSets']);
        self::assertSame(['EXCISE', 'VAT'], array_column($compiled['taxRuleSets'][2]['rules'], 'id'));
    }

    public function testRejectsConflictingContextsFromSupplyAndDistributionPresets(): void
    {
        $this->writePreset('TEST.SUPPLY', 'ENERGY_PURCHASE', 'energy-purchase', 'ENERGY', 'PL', 'HOUSEHOLD');
        $this->writePreset('TEST.DISTRIBUTION', 'DISTRIBUTION_VARIABLE', 'distribution-variable', 'NETWORK', 'XX', 'HOUSEHOLD');
        file_put_contents($this->presetDirectory . '/index.json', json_encode([
            'presets' => [
                ['id' => 'TEST.SUPPLY', 'path' => 'supply.json'],
                ['id' => 'TEST.DISTRIBUTION', 'path' => 'distribution.json'],
            ],
        ], JSON_THROW_ON_ERROR));

        $plan = [
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycles' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'anchor' => '2026-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ]],
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'components' => [
                    ['kind' => 'ENERGY_PURCHASE', 'presetId' => 'TEST.SUPPLY', 'componentId' => 'energy-purchase', 'values' => []],
                    ['kind' => 'DISTRIBUTION_VARIABLE', 'presetId' => 'TEST.DISTRIBUTION', 'componentId' => 'distribution-variable', 'values' => []],
                ],
            ]],
        ];

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('Conflicting tax contexts');
        (new CostPlanCompiler(new TariffPresetCatalog($this->presetDirectory)))->compileToArray($plan);
    }

    public function testInlineOnlyPlanWorksWithExplicitTaxContext(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray($this->inlineOnlyPlan([
            'jurisdiction' => 'PL',
            'customerClass' => 'HOUSEHOLD',
        ]));

        self::assertCount(3, $compiled['taxRuleSets']);
        self::assertSame('supplier-fixed', $compiled['periods'][0]['components'][0]['id']);
    }

    public function testInlineOnlyPlanWithoutTaxContextFails(): void
    {
        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('Unable to infer tax context for CostPlan');
        (new CostPlanCompiler())->compileToArray($this->inlineOnlyPlan(null));
    }

    /** @return array<string, mixed> */
    private function normalPlan(): array
    {
        return [
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycles' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'anchor' => '2026-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ]],
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'components' => [
                    ['kind' => 'ENERGY_PURCHASE', 'presetId' => 'PL.TAURON_SPRZEDAZ.G11', 'componentId' => 'energy-purchase', 'values' => []],
                    ['kind' => 'DISTRIBUTION_VARIABLE', 'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11', 'componentId' => 'distribution-variable', 'values' => []],
                ],
            ]],
        ];
    }

    /** @param array<string, string>|null $taxContext @return array<string, mixed> */
    private function inlineOnlyPlan(?array $taxContext): array
    {
        $plan = [
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycles' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2026-02-01T00:00:00+01:00',
                'anchor' => '2026-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ]],
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2026-02-01T00:00:00+01:00',
                'components' => [[
                    'kind' => 'SUPPLIER_FIXED',
                    'componentId' => 'supplier-fixed',
                    'rate' => '12.00',
                    'per' => 'BILLING_PERIOD',
                    'taxTreatment' => ['included' => []],
                ]],
            ]],
        ];
        if ($taxContext !== null) {
            $plan['taxContext'] = $taxContext;
        }
        return $plan;
    }

    private function writePreset(
        string $id,
        string $kind,
        string $componentId,
        string $category,
        string $jurisdiction,
        string $customerClass,
    ): void {
        $filename = $kind === 'ENERGY_PURCHASE' ? 'supply.json' : 'distribution.json';
        file_put_contents($this->presetDirectory . '/' . $filename, json_encode([
            'version' => 1,
            'id' => $id,
            'taxContext' => ['jurisdiction' => $jurisdiction, 'customerClass' => $customerClass],
            'validFrom' => '2026-01-01T00:00:00+01:00',
            'validTo' => '2027-01-01T00:00:00+01:00',
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'inputs' => [],
            'billingDefinitionTemplate' => [
                'version' => 1,
                'currency' => 'PLN',
                'timezone' => 'Europe/Warsaw',
                'periods' => [[
                    'validFrom' => null,
                    'validTo' => null,
                    'components' => [[
                        'id' => $componentId,
                        'kind' => $kind,
                        'category' => $category,
                        'taxTreatment' => ['included' => []],
                        'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
                        'rate' => ['type' => 'CONSTANT', 'value' => '1', 'unit' => 'PLN/kWh'],
                    ]],
                ]],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($directory . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($directory);
    }
}
