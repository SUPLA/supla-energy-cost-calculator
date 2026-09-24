<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Plan\CostPlanDefinitionParser;

final class CostPlanComponentMultiplicityTest extends TestCase
{
    public function testAllowsMultipleComponentsOfSameKindWhenIdsDiffer(): void
    {
        $plan = $this->plan([
            ['kind' => 'DISTRIBUTION_VARIABLE', 'presetId' => 'TEST.ONE', 'componentId' => 'network-variable', 'values' => []],
            ['kind' => 'DISTRIBUTION_VARIABLE', 'presetId' => 'TEST.TWO', 'componentId' => 'quality-fee', 'values' => []],
        ]);

        $parsed = (new CostPlanDefinitionParser())->parse($plan);

        self::assertCount(2, $parsed->periods[0]->components);
    }

    public function testRejectsDuplicateComponentIdEvenAcrossDifferentKinds(): void
    {
        $plan = $this->plan([
            ['kind' => 'ENERGY_PURCHASE', 'presetId' => 'TEST.ONE', 'componentId' => 'same-id', 'values' => []],
            ['kind' => 'DISTRIBUTION_VARIABLE', 'presetId' => 'TEST.TWO', 'componentId' => 'same-id', 'values' => []],
        ]);

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage("duplicates componentId 'same-id'");
        (new CostPlanDefinitionParser())->parse($plan);
    }

    /** @param list<array<string, mixed>> $components */
    private function plan(array $components): array
    {
        return [
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'priceBasis' => 'NET',
            'billingCycles' => [['anchor' => '2026-01-01', 'length' => 1, 'unit' => 'MONTH']],
            'periods' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2027-01-01T00:00:00+01:00',
                'components' => $components,
            ]],
        ];
    }
}
