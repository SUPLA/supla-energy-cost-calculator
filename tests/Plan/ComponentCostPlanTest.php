<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Plan\CostPlanDefinition;
use Supla\EnergyCostCalculator\Plan\CostPlanDefinitionParser;
use Supla\EnergyCostCalculator\Plan\CostComponentKind;
use Supla\EnergyCostCalculator\Plan\CostPlanPeriod;
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
        ), $this->periodicOnly($compiled));
        self::assertSame('12', $result->periodicTotal);
        self::assertSame('12', $result->total);
        self::assertSame('12', $result->billingPeriods[0]['costs']['periodic']['total']);
    }

    public function testPlanPeriodCanExtendBeyondPresetValidity(): void
    {
        $plan = $this->plan();
        $plan['periods'][0]['validFrom'] = null;
        $plan['periods'][1]['validTo'] = '2027-02-01T00:00:00+01:00';
        $plan['billingCycles'][0]['validFrom'] = null;
        $plan['billingCycles'][0]['validTo'] = '2027-02-01T00:00:00+01:00';

        $compiled = (new CostPlanCompiler())->compileToArray($plan);
        self::assertNull($compiled['periods'][0]['validFrom']);
        self::assertSame('2027-02-01T00:00:00+01:00', $compiled['periods'][1]['validTo']);
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

    public function testCompilesAndCalculatesAPlanWithOpenPeriodBoundaries(): void
    {
        $plan = [
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'priceBasis' => 'NET',
            'billingCycles' => [[
                'anchor' => '2026-01-01',
                'length' => 1,
                'unit' => 'MONTH',
            ]],
            'periods' => [[
                'components' => [[
                    'kind' => 'SUPPLIER_FIXED',
                    'rate' => '12.00',
                    'per' => 'BILLING_PERIOD',
                ]],
            ]],
        ];

        $compiled = (new CostPlanCompiler())->compileToArray($plan);
        self::assertNull($compiled['periods'][0]['validFrom']);
        self::assertNull($compiled['periods'][0]['validTo']);

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource([]),
            new InMemoryReferenceDataSource(),
        ))->calculate('meter', new TimeRange(
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2026-02-01T00:00:00+01:00'),
        ), $compiled);

        self::assertSame('12', $result->total);
    }

    public function testAllowsOpenStartAndEndAroundContiguousPeriods(): void
    {
        $plan = $this->plan();
        $plan['periods'][0]['validFrom'] = null;
        $plan['periods'][1]['validTo'] = null;

        $parsed = (new CostPlanDefinitionParser())->parse($plan);
        self::assertNull($parsed->periods[0]->validFrom);
        self::assertNull($parsed->periods[1]->validTo);
    }

    public function testRejectsGapsBetweenCostPlanPeriods(): void
    {
        $plan = $this->plan();
        $plan['periods'][1]['validFrom'] = '2026-01-16T00:00:00+01:00';

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('contiguous and ordered');
        (new CostPlanDefinitionParser())->parse($plan);
    }

    public function testCompilerRejectsGapsInPrebuiltCostPlanDefinition(): void
    {
        $parsed = (new CostPlanDefinitionParser())->parse($this->plan());
        $periods = $parsed->periods;
        $periods[1] = new CostPlanPeriod(
            new \DateTimeImmutable('2026-01-16T00:00:00+01:00'),
            $periods[1]->validTo,
            $periods[1]->components,
        );
        $plan = new CostPlanDefinition(
            $parsed->billingCycles,
            $parsed->currency,
            $parsed->timezone,
            $parsed->priceBasis,
            $periods,
        );

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('contiguous and ordered');
        (new CostPlanCompiler())->compile($plan);
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
        $plan['periods'][0]['components'][0]['kind'] = 'DISTRIBUTION_VARIABLE';

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('does not match DISTRIBUTION_VARIABLE');
        (new CostPlanCompiler())->compile($plan);
    }

    public function testRejectsDuplicateComponentId(): void
    {
        $plan = $this->plan();
        $plan['periods'][0]['components'][] = $plan['periods'][0]['components'][0];

        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage("duplicates componentId 'energy-purchase'");
        (new CostPlanCompiler())->compile($plan);
    }

    public function testRejectsAmbiguousFixedRateChangeWithinBillingCycle(): void
    {
        $plan = $this->plan();
        $plan['periods'][1]['components'][2]['rate'] = '13.00';
        $definition = (new CostPlanCompiler())->compileToArray($plan);

        $this->expectException(CalculationException::class);
        $this->expectExceptionMessage('changes within one charge period');
        (new CostCalculator(
            new InMemoryEnergyDeltaSource([]),
            new InMemoryReferenceDataSource(),
        ))->calculate('meter', new TimeRange(
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2026-02-01T00:00:00+01:00'),
        ), $this->periodicOnly($definition));
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function periodicOnly(array $definition): array
    {
        foreach ($definition['periods'] as &$period) {
            $period['components'] = array_values(array_filter(
                $period['components'],
                static fn(array $component): bool => ($component['quantity']['type'] ?? null) === 'PERIOD',
            ));
        }
        unset($period);

        return $definition;
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $components = [
            [
                'kind' => 'ENERGY_PURCHASE',
                'presetId' => 'PL.TAURON_SPRZEDAZ.G11.2026',
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
