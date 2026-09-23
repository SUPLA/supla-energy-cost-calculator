<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Plan\CostPlanDefinitionParser;
use Supla\EnergyCostCalculator\Plan\CostComponentKind;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class ComponentCostPlanTest extends TestCase
{
    public function testComposesIndependentTariffsAndFixedFee(): void
    {
        $plan = $this->plan();
        $compiled = (new CostPlanCompiler())->compileToArray($plan);

        self::assertCount(2, $compiled['periods']);
        foreach ($compiled['periods'] as $period) {
            self::assertSame(['energy-purchase', 'distribution-variable', 'supplier-fixed'], array_column($period['components'], 'id'));
            self::assertSame('ALWAYS', $period['components'][0]['selector']['type']);
            self::assertSame('WEEKLY_SCHEDULE', $period['components'][1]['selector']['type']);
            self::assertSame('PERIOD', $period['components'][2]['quantity']['type']);
        }
        self::assertSame('0.71', $compiled['periods'][0]['components'][0]['rate']['value']);
        self::assertSame('0.72', $compiled['periods'][1]['components'][0]['rate']['value']);
        self::assertSame('0.3844', $compiled['periods'][0]['components'][1]['rate']['rates']['DAY']);
        self::assertSame('2026-02-01T00:00:00+01:00', $compiled['billingCycles'][0]['validTo']);

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource([]),
            new InMemoryReferenceDataSource(),
        ))->calculate('meter', new TimeRange(
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2026-02-01T00:00:00+01:00'),
        ), $compiled);
        self::assertSame('12', $result->periodicTotal);
        self::assertSame('12', $result->total);
        self::assertSame('12', $result->billingPeriods[0]['costs']['periodic']['total']);
    }

    public function testRejectsMissingPresetCoverage(): void
    {
        $plan = $this->plan();
        $plan['periods'][1]['validTo'] = '2027-02-01T00:00:00+01:00';
        $plan['billingCycles'][0]['validTo'] = '2027-02-01T00:00:00+01:00';

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('does not cover');
        (new CostPlanCompiler())->compile($plan);
    }

    public function testCanAddFixedDistributionAsSeparateKind(): void
    {
        $plan = $this->plan();
        foreach ($plan['periods'] as &$period) {
            $period['components'][] = ['kind' => 'DISTRIBUTION_FIXED', 'rate' => '4.00', 'per' => 'MONTH'];
        }
        unset($period);

        $compiled = (new CostPlanCompiler())->compileToArray($plan);
        self::assertSame('NETWORK', $compiled['periods'][0]['components'][3]['category']);
        self::assertSame('MONTH', $compiled['periods'][0]['components'][3]['quantity']['period']);
    }

    public function testParsesPersistedVersionTwoJson(): void
    {
        $json = json_encode($this->plan(), JSON_THROW_ON_ERROR);
        $plan = (new CostPlanDefinitionParser())->parse($json);

        self::assertCount(2, $plan->periods);
        self::assertSame(CostComponentKind::ENERGY_PURCHASE, $plan->periods[0]->components[0]->kind);
        self::assertSame('NET', $plan->priceBasis);
    }

    public function testRejectsDateTimeBillingCycleAnchor(): void
    {
        $plan = $this->plan();
        $plan['billingCycles'][0]['anchor'] = '2026-01-01T00:00:00+01:00';

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('billingCycles[0].anchor must be an ISO-8601 date');
        (new CostPlanDefinitionParser())->parse($plan);
    }

    public function testRejectsLegacyEntryPlan(): void
    {
        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('Cost plan version must be 2');
        (new CostPlanDefinitionParser())->parse([
            'version' => 1,
            'entries' => [],
        ]);
    }

    public function testRejectsPresetComponentAssignedToWrongKind(): void
    {
        $plan = $this->plan();
        $plan['periods'][0]['components'][1]['componentId'] = 'energy-purchase';
        $plan['periods'][0]['components'][1]['values'] = ['energy.DAY' => '0.60', 'energy.NIGHT' => '0.40'];

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('does not match DISTRIBUTION_VARIABLE');
        (new CostPlanCompiler())->compile($plan);
    }

    public function testRejectsDuplicateComponentKind(): void
    {
        $plan = $this->plan();
        $plan['periods'][0]['components'][] = $plan['periods'][0]['components'][0];

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('duplicate component kind');
        (new CostPlanCompiler())->compile($plan);
    }

    public function testRejectsAmbiguousFixedRateChangeWithinBillingCycle(): void
    {
        $plan = $this->plan();
        $plan['periods'][1]['components'][2]['rate'] = '13.00';
        $definition = (new CostPlanCompiler())->compile($plan);

        $this->expectException(CalculationException::class);
        $this->expectExceptionMessage('changes within one charge period');
        (new CostCalculator(
            new InMemoryEnergyDeltaSource([]),
            new InMemoryReferenceDataSource(),
        ))->calculate('meter', new TimeRange(
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2026-02-01T00:00:00+01:00'),
        ), $definition);
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $components = [
            [
                'kind' => 'ENERGY_PURCHASE',
                'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                'componentId' => 'energy-purchase',
                'values' => ['energy.rate' => '0.71'],
            ],
            [
                'kind' => 'DISTRIBUTION_VARIABLE',
                'presetId' => 'PL.ENERGA_OPERATOR.G12.2026',
                'componentId' => 'distribution-variable',
                'values' => [],
            ],
            [
                'kind' => 'SUPPLIER_FIXED',
                'rate' => '12.00',
                'per' => 'BILLING_PERIOD',
            ],
        ];
        return [
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'priceBasis' => 'NET',
            'billingCycles' => [[
                'validFrom' => '2026-01-01T00:00:00+01:00',
                'validTo' => '2026-02-01T00:00:00+01:00',
                'anchor' => '2026-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ]],
            'periods' => [
                [
                    'validFrom' => '2026-01-01T00:00:00+01:00',
                    'validTo' => '2026-01-15T00:00:00+01:00',
                    'components' => $components,
                ],
                [
                    'validFrom' => '2026-01-15T00:00:00+01:00',
                    'validTo' => '2026-02-01T00:00:00+01:00',
                    'components' => [
                        [...$components[0], 'values' => ['energy.rate' => '0.72']],
                        $components[1],
                        $components[2],
                    ],
                ],
            ],
        ];
    }
}
