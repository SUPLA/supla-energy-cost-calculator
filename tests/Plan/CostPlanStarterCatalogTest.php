<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Plan\CostPlanStarter;
use Supla\EnergyCostCalculator\Plan\CostPlanStarterCatalog;

final class CostPlanStarterCatalogTest extends TestCase
{
    public function testListsSimplePolishStarters(): void
    {
        $starters = (new CostPlanStarterCatalog())->starters();

        self::assertCount(13, $starters);
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
