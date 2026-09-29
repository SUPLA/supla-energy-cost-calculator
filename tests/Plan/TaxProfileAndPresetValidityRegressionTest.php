<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;
use Supla\EnergyCostCalculator\Tax\TaxContext;
use Supla\EnergyCostCalculator\Tax\TaxProfileResolver;

final class TaxProfileAndPresetValidityRegressionTest extends TestCase
{
    public function testBundledPolishAssignmentsReuseStandardProfileAroundThe2022Shield(): void
    {
        $resolver = new TaxProfileResolver();
        $context = new TaxContext('PL', 'HOUSEHOLD');

        $resolved = $resolver->resolve(
            $context,
            new \DateTimeImmutable('2019-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2024-01-01T00:00:00+01:00'),
            'PLN',
        );

        self::assertSame([
            'PL.HOUSEHOLD.VAT23_EXCISE5.V1',
            'PL.HOUSEHOLD.VAT5_EXCISE0.V1',
            'PL.HOUSEHOLD.VAT23_EXCISE5.V1',
        ], array_column($resolved, 'profileId'));
        self::assertSame('2019-01-01T00:00:00+01:00', $resolved[0]['validFrom']?->format(DATE_ATOM));
        self::assertSame('2022-01-01T00:00:00+01:00', $resolved[0]['validTo']?->format(DATE_ATOM));
        self::assertSame('2022-01-01T00:00:00+01:00', $resolved[1]['validFrom']?->format(DATE_ATOM));
        self::assertSame('2023-01-01T00:00:00+01:00', $resolved[1]['validTo']?->format(DATE_ATOM));
        self::assertSame('2023-01-01T00:00:00+01:00', $resolved[2]['validFrom']?->format(DATE_ATOM));
        self::assertSame('2024-01-01T00:00:00+01:00', $resolved[2]['validTo']?->format(DATE_ATOM));
    }

    public function testBundledPolishAssignmentsRejectHistoryBeforeExciseFiveRegime(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('do not cover');

        (new TaxProfileResolver())->resolve(
            new TaxContext('PL', 'HOUSEHOLD'),
            new \DateTimeImmutable('2018-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2019-01-01T00:00:00+01:00'),
            'PLN',
        );
    }

    public function testBundledPolishStandardProfileRemainsOpenEndedAfter2023(): void
    {
        $resolved = (new TaxProfileResolver())->resolve(
            new TaxContext('PL', 'HOUSEHOLD'),
            new \DateTimeImmutable('2030-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2031-01-01T00:00:00+01:00'),
            'PLN',
        );

        self::assertSame(['PL.HOUSEHOLD.VAT23_EXCISE5.V1'], array_column($resolved, 'profileId'));
        self::assertSame('2030-01-01T00:00:00+01:00', $resolved[0]['validFrom']?->format(DATE_ATOM));
        self::assertSame('2031-01-01T00:00:00+01:00', $resolved[0]['validTo']?->format(DATE_ATOM));
    }

    public function testContractOfferCanApplyAfterItsCatalogueAvailabilityWindow(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
            'version' => 2,
            'currency' => 'PLN',
            'timezone' => 'Europe/Warsaw',
            'billingCycles' => [[
                'validFrom' => '2026-11-15T00:00:00+01:00',
                'validTo' => '2026-12-15T00:00:00+01:00',
                'anchor' => '2026-11-15',
                'length' => 1,
                'unit' => 'MONTH',
            ]],
            'periods' => [[
                'validFrom' => '2026-11-15T00:00:00+01:00',
                'validTo' => '2026-12-15T00:00:00+01:00',
                'components' => [[
                    'kind' => 'ENERGY_PURCHASE',
                    'presetId' => 'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.KONSUMENT.2025_11',
                    'componentId' => 'energy-purchase',
                    'values' => [],
                ]],
            ]],
        ]);

        self::assertSame('2026-11-15T00:00:00+01:00', $compiled['periods'][0]['validFrom']);
        self::assertSame('2026-12-15T00:00:00+01:00', $compiled['periods'][0]['validTo']);
    }

    public function testOpenGenericPlanUsesBillingCoverageForTaxResolution(): void
    {
        $compiled = (new CostPlanCompiler())->compileToArray([
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
                'components' => [[
                    'kind' => 'ENERGY_PURCHASE',
                    'presetId' => 'PL.GENERIC.ENERGY_PURCHASE.CONSTANT.V1',
                    'componentId' => 'energy-purchase',
                    'values' => [
                        'energy.rate' => '0.67',
                        'energy.taxTreatment' => 'WITH_EXCISE',
                    ],
                ]],
            ]],
        ]);

        self::assertNull($compiled['periods'][0]['validFrom']);
        self::assertNull($compiled['periods'][0]['validTo']);
        self::assertSame('2026-01-01T00:00:00+01:00', $compiled['taxRuleSets'][0]['validFrom']);
        self::assertSame('2027-01-01T00:00:00+01:00', $compiled['taxRuleSets'][0]['validTo']);
    }

    public function testContractOfferTemplatesSeparateAvailabilityFromExecutableApplicability(): void
    {
        $compiler = new TariffPresetCompiler();

        foreach ([
            'PL.ENEA.CENY_DYNAMICZNE.DI12011227_G.KONSUMENT',
            'PL.ENEA.CENY_DYNAMICZNE.DI12011227_G.PROSUMENT',
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.KONSUMENT.2025_11',
            'PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.PROSUMENT.2025_11',
        ] as $presetId) {
            $compiled = $compiler->compileComponentToArray($presetId, 'energy-purchase', []);
            self::assertNull($compiled['periods'][0]['validFrom'], $presetId);
            self::assertNull($compiled['periods'][0]['validTo'], $presetId);
        }

        foreach ([
            'PL.PGE_OBROT.DYNAMICZNA_ENERGIA.G1X.KONSUMENT.2026',
            'PL.PGE_OBROT.DYNAMICZNA_ENERGIA.G1X.PROSUMENT.2026',
        ] as $presetId) {
            $compiled = $compiler->compileComponentToArray($presetId, 'energy-purchase', []);
            self::assertSame('2026-01-01T00:00:00+01:00', $compiled['periods'][0]['validFrom'], $presetId);
            self::assertSame('2027-01-01T00:00:00+01:00', $compiled['periods'][0]['validTo'], $presetId);
        }
    }
}
