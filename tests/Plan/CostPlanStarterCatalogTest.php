<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Plan\CostPlanStarterCatalog;

final class CostPlanStarterCatalogTest extends TestCase
{
    public function testListsSimplePolishStarters(): void
    {
        $starters = (new CostPlanStarterCatalog())->starters();

        self::assertCount(13, $starters);
        self::assertSame('PL.STARTER.TAURON_DYSTRYBUCJA.G11.2026', $starters[0]['id']);
        self::assertSame('G11', $starters[0]['tariffGroup']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $starters[0]['revision']);
        self::assertArrayNotHasKey('plan', $starters[0]);
    }

    public function testEveryBundledStarterCompiles(): void
    {
        $catalog = new CostPlanStarterCatalog();
        $compiler = new CostPlanCompiler();

        foreach ($catalog->starters() as $metadata) {
            $compiled = $compiler->compileToArray($catalog->get($metadata['id'])->plan);
            self::assertNotEmpty($compiled['periods'], $metadata['id']);
        }
    }

    public function testStarterReturnsARegularPersistableCostPlan(): void
    {
        $starter = (new CostPlanStarterCatalog())->get('PL.STARTER.TAURON_DYSTRYBUCJA.G11.2026');

        self::assertSame(2, $starter->plan['version']);
        self::assertSame('PL.TAURON_SPRZEDAZ.G11.2026', $starter->plan['periods'][0]['components'][0]['presetId']);
        self::assertSame('PL.TAURON_DYSTRYBUCJA.G11.2026', $starter->plan['periods'][0]['components'][1]['presetId']);
        self::assertSame([], $starter->plan['periods'][0]['components'][0]['values']);
        self::assertSame([], $starter->plan['periods'][0]['components'][1]['values']);

        $compiled = (new CostPlanCompiler())->compileToArray($starter->plan);
        self::assertSame(['energy-purchase', 'distribution-variable'], array_column($compiled['periods'][0]['components'], 'id'));
    }
}
