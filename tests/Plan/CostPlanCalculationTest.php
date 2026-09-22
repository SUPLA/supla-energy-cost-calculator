<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class CostPlanCalculationTest extends TestCase
{
    public function testCompiledCostPlanCanBeCalculatedDirectly(): void
    {
        $definition = (new CostPlanCompiler())->compile([
            'version' => 1,
            'entries' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                'values' => [
                    'billingCycle.anchor' => '2026-01-15T00:00:00+01:00',
                    'energy.rate' => '0.70',
                ],
            ]],
        ]);

        $delta = new EnergyDelta(
            new \DateTimeImmutable('2026-01-20T10:00:00+01:00'),
            new \DateTimeImmutable('2026-01-20T10:15:00+01:00'),
            [QuantityType::ACTIVE_ENERGY_IMPORT->value => '1'],
        );
        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource([$delta]),
            new InMemoryReferenceDataSource(),
        ))->calculate(
            'meter',
            new TimeRange($delta->from, $delta->to),
            $definition,
        );

        self::assertSame('0.9464', $result->usageBasedTotal);
        self::assertSame('0.7', $result->usageBasedByComponent['energy-purchase']);
        self::assertSame('0.2464', $result->usageBasedByComponent['distribution-variable']);
    }
}
