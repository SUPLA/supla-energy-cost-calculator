<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;

final class CostPlanExamplesTest extends TestCase
{
    public function testBundledCostPlanExamplesCompile(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/examples/cost-plans/*.json') ?: [];
        self::assertNotEmpty($files);

        $compiler = new CostPlanCompiler();
        foreach ($files as $file) {
            $json = file_get_contents($file);
            self::assertNotFalse($json, $file);
            $definition = $compiler->compile($json);
            self::assertSame('PLN', $definition->currency, $file);
            self::assertNotEmpty($definition->periods, $file);
        }
    }
}
