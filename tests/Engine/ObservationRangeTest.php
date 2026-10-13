<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CalculationProblemPolicy;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class ObservationRangeTest extends TestCase
{
    public function testFullYearIncludesOnlySeptemberAndOctoberFixedCharges(): void
    {
        $range = $this->range('2026-01-01', '2027-01-01');
        $observation = $this->range('2026-09-03', '2026-10-09');
        $definition = $this->definition();
        $definition['periods'][0]['components'][] = [
            'id' => 'energy', 'kind' => 'ENERGY_PURCHASE', 'category' => 'ENERGY',
            'taxTreatment' => ['included' => []],
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'],
            'rate' => ['type' => 'CONSTANT', 'value' => '1', 'unit' => 'PLN/kWh'],
        ];
        $result = $this->calculator([
            $this->delta('2026-09-10T00:00:00Z', '2026-09-10T01:00:00Z'),
            $this->delta('2026-10-05T00:00:00Z', '2026-10-05T01:00:00Z'),
        ])->calculate('meter', $range, $definition, new CalculationOptions(
            includeReportSummary: true,
            observationRange: $observation,
        ));

        self::assertSame('20', $result->reportSummary['costs']['net']['periodic']['total']);
        self::assertSame('24.6', $result->reportSummary['costs']['gross']['periodic']['total']);
        self::assertSame('29.52', $result->reportSummary['costs']['gross']['total']);
        self::assertSame('5.52', $result->reportSummary['costs']['taxes']['total']);
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
        self::assertSame('20', $result->costs['net']['periodic']['total']);
        self::assertSame('2', $result->periodicCharges[0]['calculated']['units']);
        self::assertSame('0', $result->billingPeriods[0]['costs']['gross']['periodic']['total']);
        self::assertSame('12.3', $result->billingPeriods[8]['costs']['gross']['periodic']['total']);
        self::assertSame('12.3', $result->billingPeriods[9]['costs']['gross']['periodic']['total']);
        self::assertSame('0', $result->billingPeriods[10]['costs']['gross']['periodic']['total']);
        self::assertSame([], $result->warnings);
    }

    #[DataProvider('observationBoundaries')]
    public function testObservationBoundariesAreHalfOpen(string $from, string $to, string $net): void
    {
        $result = $this->calculator([])->calculate(
            'meter', $this->range('2026-01-01', '2027-01-01'), $this->definition(),
            new CalculationOptions(includeReportSummary: true, observationRange: $this->range($from, $to)),
        );
        self::assertSame($net, $result->reportSummary['costs']['net']['periodic']['total']);
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
    }

    public static function observationBoundaries(): iterable
    {
        yield 'October only' => ['2026-10-01', '2026-11-01', '10'];
        yield 'last minute of September also counts September' => ['2026-09-30T23:59:00Z', '2026-10-09', '20'];
        yield 'meter starts after selection' => ['2027-01-01', '2027-02-01', '0'];
    }

    public function testPartialPeriodsOutsideObservationDoNotMakeFixedChargesIncomplete(): void
    {
        $observation = $this->range('2026-09-03', '2026-10-09');
        $result = $this->calculator([])->calculate(
            'meter', $this->range('2026-08-10', '2026-10-20'), $this->definition(),
            new CalculationOptions(includeReportSummary: true, observationRange: $observation),
        );
        // August is not observed, September is complete and October is partial.
        self::assertSame('10', $result->reportSummary['costs']['net']['periodic']['total']);
        self::assertFalse($result->reportSummary['fixedChargesComplete']);
        self::assertSame('0', $result->billingPeriods[0]['costs']['gross']['periodic']['total']);
        self::assertNull($result->billingPeriods[2]['costs']['gross']['periodic']['total']);

        $beforeInstallation = $this->calculator([])->calculate(
            'meter', $this->range('2026-01-10', '2026-01-20'), $this->definition(),
            new CalculationOptions(includeReportSummary: true, observationRange: $observation),
        );
        self::assertSame('0', $beforeInstallation->reportSummary['costs']['gross']['periodic']['total']);
        self::assertTrue($beforeInstallation->reportSummary['fixedChargesComplete']);
    }

    public function testHistoricalRateAndTaxesAreAppliedOnlyToObservedMonths(): void
    {
        $definition = $this->definition();
        $before = $definition['periods'][0];
        $before['validTo'] = '2026-10-01';
        $after = $before;
        $after['validFrom'] = '2026-10-01';
        $after['validTo'] = null;
        $after['components'][0]['rate']['value'] = '20';
        $definition['periods'] = [$before, $after];
        $result = $this->calculator([])->calculate(
            'meter', $this->range('2026-01-01', '2027-01-01'), $definition,
            new CalculationOptions(includeReportSummary: true,
                observationRange: $this->range('2026-09-03', '2026-10-09')),
        );
        self::assertSame('30', $result->reportSummary['costs']['net']['periodic']['total']);
        self::assertSame('6.9', $result->reportSummary['costs']['taxes']['total']);
        self::assertSame('36.9', $result->reportSummary['costs']['gross']['total']);
        self::assertSame('30', $result->costs['net']['periodic']['total']);
    }

    public function testAbsentObservationRangePreservesExistingFullYearCharging(): void
    {
        $result = $this->calculator([])->calculate(
            'meter', $this->range('2026-01-01', '2027-01-01'), $this->definition(),
            new CalculationOptions(includeReportSummary: true),
        );
        self::assertSame('120', $result->reportSummary['costs']['net']['periodic']['total']);
        self::assertSame('12', $result->periodicCharges[0]['calculated']['units']);
    }

    public function testLeadingMissingDataOutsideObservationIsSilentButInternalGapWarns(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][] = [
            'id' => 'energy', 'kind' => 'ENERGY_PURCHASE', 'category' => 'ENERGY',
            'taxTreatment' => ['included' => []],
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT', 'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO', 'periodInMinutes' => 60],
            'rate' => ['type' => 'CONSTANT', 'value' => '1', 'unit' => 'PLN/kWh'],
        ];
        $range = $this->range('2026-01-01', '2027-01-01');
        $observation = $this->range('2026-09-03T00:00:00Z', '2026-10-09T00:00:00Z');
        $result = $this->calculator([
            $this->delta('2026-09-03T00:00:00Z', '2026-09-03T01:00:00Z'),
            $this->delta('2026-09-03T03:00:00Z', '2026-09-03T04:00:00Z'),
        ])->calculate('meter', $range, $definition, new CalculationOptions(
            includeReportSummary: true,
            calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED,
            observationRange: $observation,
        ));
        self::assertSame(['MISSING_METER_DATA', 'MISSING_METER_DATA'], array_column($result->warnings, 'code'));
        self::assertSame('2026-09-03T01:00:00+00:00', $result->warnings[0]['from']);
        self::assertSame('2026-09-03T03:00:00+00:00', $result->warnings[0]['to']);
        // The declared history continues beyond the final returned delta.
        self::assertSame('2026-09-03T04:00:00+00:00', $result->warnings[1]['from']);
        self::assertSame('2026-10-09T00:00:00+00:00', $result->warnings[1]['to']);
        self::assertSame('20', $result->reportSummary['costs']['net']['periodic']['total']);

        // No deltas in a selected range entirely before installation is not a warning.
        $beforeInstallation = $this->calculator([])->calculate('meter',
            $this->range('2026-01-01', '2026-02-01'), $definition,
            new CalculationOptions(includeReportSummary: true,
                calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED,
                observationRange: $observation));
        self::assertSame([], $beforeInstallation->warnings);
        self::assertSame('0', $beforeInstallation->reportSummary['costs']['net']['periodic']['total']);
    }

    public function testKnownObservationsAfterLastReturnedDeltaStillWarn(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][] = [
            'id' => 'energy', 'kind' => 'ENERGY_PURCHASE', 'category' => 'ENERGY',
            'taxTreatment' => ['included' => []],
            'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT', 'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO', 'periodInMinutes' => 60],
            'rate' => ['type' => 'CONSTANT', 'value' => '1', 'unit' => 'PLN/kWh'],
        ];
        $result = $this->calculator([
            $this->delta('2026-10-09T20:00:00Z', '2026-10-09T21:00:00Z'),
        ])->calculate('meter', $this->range('2026-10-01', '2026-11-01'), $definition,
            new CalculationOptions(includeReportSummary: true,
                calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED,
                observationRange: $this->range('2026-10-09T20:00:00Z', '2026-10-09T22:00:00Z')));
        self::assertSame(['MISSING_METER_DATA'], array_column($result->warnings, 'code'));
        self::assertSame('2026-10-09T21:00:00+00:00', $result->warnings[0]['from']);
        self::assertSame('2026-10-09T22:00:00+00:00', $result->warnings[0]['to']);
    }

    private function calculator(array $deltas): CostCalculator
    {
        return new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource());
    }

    private function delta(string $from, string $to): EnergyDelta
    {
        return new EnergyDelta(new \DateTimeImmutable($from), new \DateTimeImmutable($to), [
            'ACTIVE_ENERGY_IMPORT' => '2', 'ACTIVE_ENERGY_EXPORT' => '0',
        ]);
    }

    private function range(string $from, string $to): TimeRange
    {
        return new TimeRange(new \DateTimeImmutable($from, new \DateTimeZone('UTC')), new \DateTimeImmutable($to, new \DateTimeZone('UTC')));
    }

    private function definition(): array
    {
        return [
            'version' => 1,
            'currency' => 'PLN',
            'timezone' => 'UTC',
            'billingCycle' => ['anchor' => '2026-01-01', 'length' => 1, 'unit' => 'MONTH'],
            'taxRuleSets' => [['validFrom' => null, 'validTo' => null, 'rules' => [
                ['id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['SUPPLIER_FIXED', 'ENERGY_PURCHASE'], 'rate' => '0.23', 'base' => 'CURRENT_SUBTOTAL'],
            ]]],
            'periods' => [['validFrom' => null, 'validTo' => null, 'components' => [[
                'id' => 'fixed', 'kind' => 'SUPPLIER_FIXED', 'category' => 'SERVICE',
                'taxTreatment' => ['included' => []],
                'quantity' => ['type' => 'PERIOD', 'period' => 'BILLING_PERIOD', 'prorate' => false],
                'rate' => ['type' => 'CONSTANT', 'value' => '10', 'unit' => 'PLN/period'],
            ]]]],
        ];
    }
}
