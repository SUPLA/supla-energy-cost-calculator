<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Plan\CostPlanStarter;
use Supla\EnergyCostCalculator\Plan\CostPlanStarterCatalog;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class CostPlanStarterCatalogTest extends TestCase
{
    public function testListsSimplePolishStarters(): void
    {
        $starters = (new CostPlanStarterCatalog())->starters();

        self::assertCount(21, $starters);
        self::assertSame('PL.STARTER.TAURON_DYSTRYBUCJA.G11', $starters[0]['id']);
        self::assertSame('G11', $starters[0]['tariffGroup']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $starters[0]['revision']);
        self::assertArrayNotHasKey('components', $starters[0]);
        self::assertArrayNotHasKey('plan', $starters[0]);
    }

    public function testEveryBundledStarterCanPopulateAnOpenEndedUserPlan(): void
    {
        $catalog = new CostPlanStarterCatalog();
        $compiler = new CostPlanCompiler();

        foreach ($catalog->starters() as $metadata) {
            $starter = $catalog->get($metadata['id']);
            $compiled = $compiler->compileToArray($this->userPlan($starter));

            self::assertCount(1, $compiled['periods'], $metadata['id']);
            self::assertNull($compiled['periods'][0]['validFrom'], $metadata['id']);
            self::assertNull($compiled['periods'][0]['validTo'], $metadata['id']);
            self::assertNotEmpty($compiled['periods'][0]['components'], $metadata['id']);
        }
    }

    public function testStarterReturnsOnlyAComponentRecipe(): void
    {
        $starter = (new CostPlanStarterCatalog())->get('PL.STARTER.TAURON_DYSTRYBUCJA.G11');

        self::assertFalse(property_exists($starter, 'plan'));
        self::assertSame('PL.TAURON_SPRZEDAZ.G11.2026', $starter->components[0]['presetId']);
        self::assertSame('PL.TAURON_DYSTRYBUCJA.G11.2026', $starter->components[1]['presetId']);
        self::assertSame([], $starter->components[0]['values']);
        self::assertSame([], $starter->components[1]['values']);
    }

    public function testG11StarterAppliesHourlyImportExportNetting(): void
    {
        $starter = (new CostPlanStarterCatalog())->get('PL.STARTER.TAURON_DYSTRYBUCJA.G11');
        $definition = (new CostPlanCompiler())->compileToArray($this->userPlan($starter));

        foreach ($definition['periods'][0]['components'] as $component) {
            self::assertSame('ACTIVE_ENERGY_IMPORT', $component['quantity']['type']);
            self::assertSame('IMPORT_MINUS_EXPORT_CAP_ZERO', $component['quantity']['strategy']);
            self::assertSame(60, $component['quantity']['periodInMinutes']);
        }

        $deltas = [
            $this->delta('2026-01-02T10:00:00+01:00', '2026-01-02T10:15:00+01:00', '0.4', '0'),
            $this->delta('2026-01-02T10:15:00+01:00', '2026-01-02T10:30:00+01:00', '0.3', '0.5'),
            $this->delta('2026-01-02T10:30:00+01:00', '2026-01-02T10:45:00+01:00', '0.4', '0.8'),
            $this->delta('2026-01-02T10:45:00+01:00', '2026-01-02T11:00:00+01:00', '0', '0.8'),
        ];
        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange($deltas[0]->from, $deltas[array_key_last($deltas)]->to),
            $definition,
        );

        self::assertSame('0', $result->usageBasedTotal);
    }

    private function delta(string $from, string $to, string $import, string $export): EnergyDelta
    {
        return new EnergyDelta(new \DateTimeImmutable($from), new \DateTimeImmutable($to), [
            QuantityType::ACTIVE_ENERGY_IMPORT->value => $import,
            QuantityType::ACTIVE_ENERGY_EXPORT->value => $export,
        ]);
    }

    /** @return array<string, mixed> */
    private function userPlan(CostPlanStarter $starter): array
    {
        return [
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'priceBasis' => 'NET',
            'billingCycles' => [[
                'length' => 1,
                'unit' => 'MONTH',
            ]],
            'periods' => [[
                'components' => $starter->components,
            ]],
        ];
    }
}
