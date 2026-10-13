<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Engine\CalculationProblemPolicy;
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Math\NativeDecimalMath;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\ReferenceInterval;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryEnergyDeltaSource;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class ReportSummaryTest extends TestCase
{
    #[DataProvider('ranges')]
    public function testSelectedRangeDeterminesFixedCharges(string $from, string $to, array $readings, string $fixed, bool $complete): void
    {
        $result = $this->calculate($this->definition(), $from, $to, $readings);
        $report = $result->reportSummary;
        $math = new NativeDecimalMath();
        $usage = (string)(count($readings) * 2);
        self::assertSame($complete, $report['fixedChargesComplete']);
        self::assertSame($usage, $report['costs']['net']['usageBased']['total']);
        self::assertSame($fixed, $report['costs']['net']['periodic']['total']);
        self::assertSame($math->multiply($fixed, '1.23'), $report['costs']['gross']['periodic']['total']);
        self::assertSame($math->multiply($math->add($usage, $fixed), '0.23'), $report['costs']['taxes']['total']);
        self::assertSame($report, $result->jsonSerialize()['reportSummary']);
        self::assertSame([], $result->warnings);
        $this->assertInvariants($report['costs']);
        if (!$complete) {
            self::assertNull($result->costs['gross']['total']);
            self::assertNull($result->costs['taxes']['total']);
            self::assertNull($result->costs['gross']['periodic']['total']);
        }
    }

    public static function ranges(): iterable
    {
        yield 'complete month' => ['2026-08-01', '2026-09-01', ['2026-08-10'], '10', true];
        yield 'two complete months' => ['2026-08-01', '2026-10-01', ['2026-08-10', '2026-09-10'], '20', true];
        yield 'partial edges with complete middle' => ['2026-08-10', '2026-10-21', ['2026-08-10', '2026-09-10', '2026-10-10'], '10', false];
        yield 'two partial periods' => ['2026-08-10', '2026-09-21', ['2026-08-10', '2026-09-10'], '0', false];
        yield 'historical fragment' => ['2026-08-10', '2026-08-21', ['2026-08-10'], '0', false];
        yield 'ongoing fragment follows same rules' => ['2026-10-01', '2026-10-14', ['2026-10-10'], '0', false];
        yield 'complete selection beyond measurements' => ['2026-10-01', '2026-11-01', ['2026-10-10'], '10', true];
        yield 'future complete period without invented usage' => ['2030-10-01', '2030-11-01', [], '10', true];
    }

    public function testNoPeriodicComponentsAndZeroRateAreNotUnknown(): void
    {
        $definition = $this->definition();
        array_pop($definition['periods'][0]['components']);
        $result = $this->calculate($definition, '2026-08-10', '2026-08-21', ['2026-08-10']);
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
        self::assertSame('0', $result->reportSummary['costs']['gross']['periodic']['total']);
        $definition = $this->definition();
        $definition['periods'][0]['components'][1]['rate']['value'] = '0';
        $result = $this->calculate($definition, '2026-08-01', '2026-09-01', ['2026-08-10']);
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
        self::assertSame(['fixed' => '0'], $result->reportSummary['costs']['gross']['periodic']['byComponent']);
        self::assertSame('0', $result->periodicCharges[0]['calculated']['amounts']['gross']);
        // A zero-rate component is still an applicable fixed-charge definition.
        $partial = $this->calculate($definition, '2026-08-10', '2026-08-21', []);
        self::assertFalse($partial->reportSummary['fixedChargesComplete']);
        self::assertSame('0', $partial->reportSummary['costs']['gross']['periodic']['total']);
    }

    #[DataProvider('proration')]
    public function testHistoricalRatesAndTransitionalPeriods(bool $prorate, string $expected): void
    {
        $definition = $this->definition();
        unset($definition['billingCycle']);
        $definition['billingCycles'] = [
            ['validFrom' => null, 'validTo' => '2026-06-16', 'anchor' => '2026-06-01', 'length' => 1, 'unit' => 'MONTH'],
            ['validFrom' => '2026-06-16', 'validTo' => null, 'anchor' => '2026-06-16', 'length' => 1, 'unit' => 'MONTH'],
        ];
        $first = $definition['periods'][0];
        $first['validTo'] = '2026-06-16';
        $first['components'][1]['quantity']['prorate'] = $prorate;
        $second = $first;
        $second['validFrom'] = '2026-06-16';
        $second['validTo'] = null;
        $second['components'][1]['rate']['value'] = '20';
        $definition['periods'] = [$first, $second];
        $result = $this->calculate($definition, '2026-06-01', '2026-07-16', ['2026-06-10', '2026-06-20']);
        self::assertTrue($result->billingPeriods[0]['transitional']);
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
        self::assertSame($expected, $result->reportSummary['costs']['net']['periodic']['total']);
        $this->assertInvariants($result->reportSummary['costs']);
        $partial = $this->calculate($definition, '2026-06-05', '2026-07-10', ['2026-06-10', '2026-06-20']);
        self::assertSame('0', $partial->reportSummary['costs']['net']['periodic']['total']);
        self::assertFalse($partial->reportSummary['fixedChargesComplete']);
    }

    public static function proration(): iterable
    {
        yield 'prorated nominal denominator' => [true, '25'];
        yield 'non-prorated effective periods' => [false, '30'];
    }

    #[DataProvider('taxTreatments')]
    public function testIncludedExcludedAndHistoricalTaxes(array $included, string $rate): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['rate']['value'] = $rate;
        $definition['periods'][0]['components'][0]['taxTreatment']['included'] = $included;
        $rules = [
            ['id' => 'EXCISE', 'type' => 'PER_QUANTITY', 'appliesToKinds' => ['ENERGY_PURCHASE'], 'rate' => '0.5', 'unit' => 'PLN/kWh'],
            $definition['taxRuleSets'][0]['rules'][0],
        ];
        $definition['taxRuleSets'] = [
            ['validFrom' => null, 'validTo' => '2026-09-01', 'rules' => $rules],
            ['validFrom' => '2026-09-01', 'validTo' => null, 'rules' => [$rules[0], array_replace($rules[1], ['rate' => '0.1'])]],
        ];
        $result = $this->calculate($definition, '2026-08-10', '2026-10-21', ['2026-08-10', '2026-09-10', '2026-10-10']);
        $report = $result->reportSummary;
        self::assertSame('3', $report['costs']['taxes']['byTax']['EXCISE']);
        // Included taxes are reversed per charge, with the applicable historical rate.
        $this->assertInvariants($report['costs']);
        $math = new NativeDecimalMath();
        $vat = '0';
        foreach ($result->billingPeriods as $period) {
            $vat = $math->add($vat, $period['costs']['taxes']['byTax']['VAT']);
        }
        self::assertSame($vat, $report['costs']['taxes']['byTax']['VAT']);
        self::assertSame('10', $report['costs']['net']['periodic']['total']);
    }

    public static function taxTreatments(): iterable
    {
        yield 'excluded' => [[], '1'];
        yield 'included excise' => [['EXCISE'], '1.5'];
        yield 'included excise and VAT' => [['EXCISE', 'VAT'], '1.845'];
    }

    #[DataProvider('periodicUnits')]
    public function testEveryPeriodicUnitRetainsItsConfiguredRulesInReports(string $unit, bool $prorate): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][1]['quantity'] = ['type' => 'PERIOD', 'period' => $unit, 'prorate' => $prorate];
        $full = $this->calculate($definition, '2026-09-01', '2026-10-01', []);
        $edges = $this->calculate($definition, '2026-08-10', '2026-10-21', []);
        self::assertSame($full->costs, $full->reportSummary['costs']);
        self::assertSame($full->reportSummary['costs'], $edges->reportSummary['costs']);
        self::assertFalse($edges->reportSummary['fixedChargesComplete']);
        $partial = $this->calculate($definition, '2026-08-10', '2026-08-21', []);
        self::assertSame('0', $partial->reportSummary['costs']['gross']['periodic']['total']);
    }

    public static function periodicUnits(): iterable
    {
        foreach (['BILLING_PERIOD', 'DAY', 'WEEK', 'MONTH', 'YEAR'] as $unit) {
            yield $unit . ' prorated' => [$unit, true];
            yield $unit . ' non-prorated' => [$unit, false];
        }
    }

    public function testReportingPreservesComponentAndZoneBreakdowns(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['selector'] = [
            'type' => 'WEEKLY_SCHEDULE', 'timezone' => 'UTC',
            'rules' => [['zone' => 'ALL', 'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], 'from' => '00:00', 'to' => '24:00']],
        ];
        $result = $this->calculate($definition, '2026-08-10', '2026-10-21', ['2026-08-10', '2026-09-10', '2026-10-10']);
        self::assertSame(['energy' => '6'], $result->reportSummary['costs']['net']['usageBased']['byComponent']);
        self::assertSame(['ALL' => '6'], $result->reportSummary['costs']['net']['usageBased']['byZone']);
        self::assertSame(['ALL' => '7.38'], $result->reportSummary['costs']['gross']['usageBased']['byZone']);
        self::assertSame(['fixed' => '12.3'], $result->reportSummary['costs']['gross']['periodic']['byComponent']);
    }

    public function testUnavailableFutureFixedTaxRulesStillFailUnderSkipAffected(): void
    {
        $definition = $this->definition();
        $definition['taxRuleSets'][0]['validTo'] = '2026-10-01';
        $this->expectException(CalculationException::class);
        $this->expectExceptionMessage('Tax rule-set history does not cover');
        $this->calculate($definition, '2026-10-01', '2026-11-01', [], true);
    }

    public function testMissingIntervalsInsideANettingWindowAreNotInvented(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += ['strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO', 'periodInMinutes' => 60];
        $deltas = [
            new EnergyDelta(new \DateTimeImmutable('2026-10-10T00:00:00Z'), new \DateTimeImmutable('2026-10-10T00:15:00Z'), ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0']),
            new EnergyDelta(new \DateTimeImmutable('2026-10-10T00:45:00Z'), new \DateTimeImmutable('2026-10-10T01:00:00Z'), ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0']),
        ];
        $result = (new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource()))
            ->calculate('meter', $this->range('2026-10-01', '2026-11-01'), $definition,
                new CalculationOptions(includeCharges: true, includeReportSummary: true, calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));
        self::assertSame([], $result->charges);
        self::assertSame('0', $result->reportSummary['costs']['gross']['usageBased']['total']);
        self::assertSame('12.3', $result->reportSummary['costs']['gross']['total']);
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
        self::assertContains('CALCULATION_PROBLEM', array_column($result->warnings, 'code'));
    }

    #[DataProvider('trailingNettingRangeEnds')]
    public function testTrailingUnfinishedNettingWindowIsIgnored(string $rangeTo, array $expectedWarnings): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += [
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ];
        $deltas = [
            // The 20:00-21:00 netting window is complete.
            new EnergyDelta(new \DateTimeImmutable('2026-10-09T20:00:00Z'), new \DateTimeImmutable('2026-10-09T21:00:00Z'),
                ['ACTIVE_ENERGY_IMPORT' => '2', 'ACTIVE_ENERGY_EXPORT' => '0']),
            // The last, contiguous 21:00-22:00 window has no more readings after 21:30.
            new EnergyDelta(new \DateTimeImmutable('2026-10-09T21:00:00Z'), new \DateTimeImmutable('2026-10-09T21:15:00Z'),
                ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0']),
            new EnergyDelta(new \DateTimeImmutable('2026-10-09T21:15:00Z'), new \DateTimeImmutable('2026-10-09T21:30:00Z'),
                ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0']),
        ];
        $result = (new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource()))
            ->calculate('meter', $this->range('2026-10-09T20:00:00Z', $rangeTo), $definition,
                new CalculationOptions(includeCharges: true, includeReportSummary: true,
                    calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));

        // The partial final window contributes neither usage nor cost nor a window warning.
        self::assertSame($expectedWarnings, array_column($result->warnings, 'code'));
        self::assertSame('2', $result->usage['ACTIVE_ENERGY_IMPORT']);
        self::assertSame(1, $result->processedDeltaCount);
        self::assertSame('2.46', $result->costs['gross']['usageBased']['total']);
        self::assertSame('2', $result->costs['net']['usageBased']['total']);
        self::assertSame('2.46', $result->reportSummary['costs']['gross']['usageBased']['total']);
        self::assertCount(1, $result->charges);
        self::assertSame('2026-10-09T20:00:00+00:00', $result->charges[0]['from']);
        self::assertSame('2026-10-09T21:00:00+00:00', $result->charges[0]['to']);
    }

    public static function trailingNettingRangeEnds(): iterable
    {
        yield 'selected range ends at last reading' => ['2026-10-09T21:30:00Z', []];
        // Missing measurements after the final reading are silently ignored.
        yield 'selected range extends past last reading' => ['2026-10-09T23:00:00Z', []];
    }

    public function testFinalWindowWithMissingBeginningStillWarns(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += [
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO', 'periodInMinutes' => 60,
        ];
        $deltas = [new EnergyDelta(
            new \DateTimeImmutable('2026-10-09T21:15:00Z'),
            new \DateTimeImmutable('2026-10-09T21:30:00Z'),
            ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0'],
        )];
        $result = (new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource()))
            ->calculate('meter', $this->range('2026-10-09T21:15:00Z', '2026-10-09T21:30:00Z'), $definition,
                new CalculationOptions(includeReportSummary: true,
                    calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));
        self::assertSame(['CALCULATION_PROBLEM'], array_column($result->warnings, 'code'));
    }

    public function testIncompleteHistoricalWindowStillWarnsWhenLaterReadingsExist(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += [
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO', 'periodInMinutes' => 60,
        ];
        $deltas = [
            new EnergyDelta(new \DateTimeImmutable('2026-10-09T20:00:00Z'), new \DateTimeImmutable('2026-10-09T20:15:00Z'),
                ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0']),
            new EnergyDelta(new \DateTimeImmutable('2026-10-09T21:00:00Z'), new \DateTimeImmutable('2026-10-09T22:00:00Z'),
                ['ACTIVE_ENERGY_IMPORT' => '2', 'ACTIVE_ENERGY_EXPORT' => '0']),
        ];
        $result = (new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource()))
            ->calculate('meter', $this->range('2026-10-09T20:00:00Z', '2026-10-09T22:00:00Z'), $definition,
                new CalculationOptions(includeCharges: true, includeReportSummary: true,
                    calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));
        self::assertContains('CALCULATION_PROBLEM', array_column($result->warnings, 'code'));
        self::assertSame('2.46', $result->reportSummary['costs']['gross']['usageBased']['total']);
    }

    #[DataProvider('failedNettingReferenceCases')]
    public function testFailedNettingWindowIsRolledBackWithoutASecondWarning(?string $secondRate, string $expectedWarning): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += [
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ];
        $definition['periods'][0]['components'][0]['rate'] = [
            'type' => 'REFERENCE',
            'source' => 'TEST.PRICE',
            'unit' => 'PLN/kWh',
        ];

        $deltas = [];
        foreach ([
            ['00:00', '00:15'],
            ['00:15', '00:30'],
            ['00:30', '00:45'],
            ['00:45', '01:00'],
            ['01:00', '02:00'],
        ] as [$from, $to]) {
            $deltas[] = new EnergyDelta(
                new \DateTimeImmutable('2026-10-10T' . $from . ':00Z'),
                new \DateTimeImmutable('2026-10-10T' . $to . ':00Z'),
                ['ACTIVE_ENERGY_IMPORT' => '2', 'ACTIVE_ENERGY_EXPORT' => '0'],
            );
        }

        $series = [new ReferenceInterval(
            new \DateTimeImmutable('2026-10-10T00:00:00Z'),
            new \DateTimeImmutable('2026-10-10T00:15:00Z'),
            '1',
        )];
        if ($secondRate !== null) {
            $series[] = new ReferenceInterval(
                new \DateTimeImmutable('2026-10-10T00:15:00Z'),
                new \DateTimeImmutable('2026-10-10T01:00:00Z'),
                $secondRate,
            );
        }
        $series[] = new ReferenceInterval(
            new \DateTimeImmutable('2026-10-10T01:00:00Z'),
            new \DateTimeImmutable('2026-10-10T02:00:00Z'),
            '1',
        );

        $result = (new CostCalculator(
            new InMemoryEnergyDeltaSource($deltas),
            new InMemoryReferenceDataSource(['TEST.PRICE' => $series]),
        ))->calculate('meter', $this->range('2026-10-10T00:00:00Z', '2026-10-10T02:00:00Z'), $definition,
            new CalculationOptions(includeCharges: true, includeReportSummary: true, calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));

        // A failure in the second delta invalidates the whole first hour.
        // Only the next, complete hour is counted and priced.
        self::assertSame([$expectedWarning], array_column($result->warnings, 'code'));
        self::assertSame('2', $result->usage['ACTIVE_ENERGY_IMPORT']);
        self::assertSame('2.46', $result->reportSummary['costs']['gross']['usageBased']['total']);
        self::assertCount(1, $result->charges);
        self::assertSame('2026-10-10T01:00:00+00:00', $result->charges[0]['from']);
    }

    public static function failedNettingReferenceCases(): iterable
    {
        yield 'missing reference in the second delta' => [null, 'MISSING_REFERENCE_DATA'];
        yield 'changed rate in the second delta' => ['2', 'CALCULATION_PROBLEM'];
    }

    public function testIncompleteLeadingWindowDoesNotDiscardTheNextCompleteWindow(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += ['strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO', 'periodInMinutes' => 60];
        $deltas = [
            new EnergyDelta(new \DateTimeImmutable('2026-10-10T00:15:00Z'), new \DateTimeImmutable('2026-10-10T00:30:00Z'), ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0']),
            new EnergyDelta(new \DateTimeImmutable('2026-10-10T01:00:00Z'), new \DateTimeImmutable('2026-10-10T02:00:00Z'), ['ACTIVE_ENERGY_IMPORT' => '2', 'ACTIVE_ENERGY_EXPORT' => '0']),
        ];
        $result = (new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource()))
            ->calculate('meter', $this->range('2026-10-01', '2026-11-01'), $definition,
                new CalculationOptions(includeCharges: true, includeReportSummary: true, calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));
        self::assertCount(1, $result->charges);
        self::assertSame('2.46', $result->reportSummary['costs']['gross']['usageBased']['total']);
        self::assertSame('2', $result->usage['ACTIVE_ENERGY_IMPORT']);
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
    }

    public function testSkippedChargesDoNotDetermineFixedChargeCompleteness(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['rate'] = ['type' => 'REFERENCE', 'source' => 'missing'];
        $result = $this->calculate($definition, '2026-08-01', '2026-09-01', ['2026-08-10'], true);
        self::assertTrue($result->jsonSerialize()['incomplete']);
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
        self::assertSame('12.3', $result->reportSummary['costs']['gross']['total']);
        $this->assertInvariants($result->reportSummary['costs']);
    }

    public function testReportingUsesOnlyCompleteAvailableNettingWindowsWithoutTruncatingSelection(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += ['strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO', 'periodInMinutes' => 60];
        $result = $this->calculate($definition, '2026-10-01', '2026-11-01', ['2026-10-10'], true);
        self::assertSame('2026-11-01', $result->range->to->format('Y-m-d'));
        self::assertTrue($result->reportSummary['fixedChargesComplete']);
        self::assertSame('2.46', $result->reportSummary['costs']['gross']['usageBased']['total']);
        self::assertSame('12.3', $result->reportSummary['costs']['gross']['periodic']['total']);
        self::assertSame('MISSING_METER_DATA', $result->warnings[0]['code']);
        $this->assertInvariants($result->reportSummary['costs']);
        // The opt-in reporting path does not relax legacy coverage checks.
        $this->expectException(CalculationException::class);
        $this->calculator(['2026-10-10'])->calculate('meter', $this->range('2026-10-01', '2026-11-01'), $definition,
            new CalculationOptions(calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));
    }

    public function testTrailingMissingDataAndLastUnfinishedWindowAreNotWarnings(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += [
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ];
        $deltas = [];
        foreach ([
            ['20:00', '20:15'], ['20:15', '20:30'],
            ['20:30', '20:45'], ['20:45', '21:00'],
            ['21:00', '21:15'], ['21:15', '21:30'],
        ] as [$from, $to]) {
            $deltas[] = new EnergyDelta(
                new \DateTimeImmutable("2026-10-09T{$from}:00Z"),
                new \DateTimeImmutable("2026-10-09T{$to}:00Z"),
                ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0'],
            );
        }
        $result = (new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource()))
            ->calculate('meter', $this->range('2026-10-09T20:00:00Z', '2026-11-01T00:00:00Z'), $definition,
                new CalculationOptions(includeCharges: true, includeReportSummary: true,
                    calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));

        // The complete 20:00-21:00 window is priced; 21:00-21:30 is discarded.
        // The remainder of the requested month is simply outside available data.
        self::assertSame([], $result->warnings);
        self::assertSame('4', $result->usage['ACTIVE_ENERGY_IMPORT']);
        self::assertSame('4.92', $result->reportSummary['costs']['gross']['usageBased']['total']);
        self::assertCount(1, $result->charges);
        self::assertSame('2026-10-09T20:00:00+00:00', $result->charges[0]['from']);
    }

    public function testMiddleMeterGapStillWarnsButTrailingAbsenceDoesNot(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += [
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ];
        $deltas = [
            new EnergyDelta(new \DateTimeImmutable('2026-10-09T20:00:00Z'), new \DateTimeImmutable('2026-10-09T21:00:00Z'),
                ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0']),
            new EnergyDelta(new \DateTimeImmutable('2026-10-09T22:00:00Z'), new \DateTimeImmutable('2026-10-09T23:00:00Z'),
                ['ACTIVE_ENERGY_IMPORT' => '1', 'ACTIVE_ENERGY_EXPORT' => '0']),
        ];
        $result = (new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource()))
            ->calculate('meter', $this->range('2026-10-09T20:00:00Z', '2026-11-01T00:00:00Z'), $definition,
                new CalculationOptions(includeReportSummary: true,
                    calculationProblemPolicy: CalculationProblemPolicy::SKIP_AFFECTED));

        self::assertSame(['MISSING_METER_DATA'], array_column($result->warnings, 'code'));
        self::assertSame('2026-10-09T21:00:00+00:00', $result->warnings[0]['from']);
        self::assertSame('2026-10-09T22:00:00+00:00', $result->warnings[0]['to']);
        self::assertSame('2', $result->usage['ACTIVE_ENERGY_IMPORT']);
    }

    public function testCompletelyMissingMeterDataStillWarns(): void
    {
        $definition = $this->definition();
        $definition['periods'][0]['components'][0]['quantity'] += [
            'strategy' => 'IMPORT_MINUS_EXPORT_CAP_ZERO',
            'periodInMinutes' => 60,
        ];
        $result = $this->calculate($definition, '2026-10-01', '2026-11-01', [], true);

        self::assertSame(['MISSING_METER_DATA'], array_column($result->warnings, 'code'));
        self::assertSame('0', $result->reportSummary['costs']['gross']['usageBased']['total']);
        self::assertSame('12.3', $result->reportSummary['costs']['gross']['periodic']['total']);
    }

    public function testLegacyJsonIsUnchangedWhenReportIsNotRequested(): void
    {
        $calculator = $this->calculator(['2026-08-10']);
        $range = $this->range('2026-08-10', '2026-08-21');
        $legacy = $calculator->calculate('meter', $range, $this->definition())->jsonSerialize();
        $report = $calculator->calculate('meter', $range, $this->definition(), new CalculationOptions(false, false, CalculationProblemPolicy::STRICT, true))->jsonSerialize();
        self::assertArrayNotHasKey('reportSummary', $legacy);
        unset($report['reportSummary']);
        self::assertSame($legacy, $report);
    }

    private function assertInvariants(array $costs): void
    {
        $math = new NativeDecimalMath();
        foreach (['net', 'gross'] as $basis) {
            self::assertSame($math->add($costs[$basis]['usageBased']['total'], $costs[$basis]['periodic']['total']), $costs[$basis]['total']);
        }
        self::assertSame($math->add($costs['net']['total'], $costs['taxes']['total']), $costs['gross']['total']);
    }

    private function calculate(array $definition, string $from, string $to, array $readings, bool $skip = false): \Supla\EnergyCostCalculator\Engine\CalculationResult
    {
        return $this->calculator($readings)->calculate('meter', $this->range($from, $to), $definition,
            new CalculationOptions(includeReportSummary: true, calculationProblemPolicy: $skip ? CalculationProblemPolicy::SKIP_AFFECTED : CalculationProblemPolicy::STRICT));
    }

    private function calculator(array $readings): CostCalculator
    {
        $deltas = array_map(static fn(string $date): EnergyDelta => new EnergyDelta(new \DateTimeImmutable($date),
            new \DateTimeImmutable($date . ' +1 hour'), ['ACTIVE_ENERGY_IMPORT' => '2', 'ACTIVE_ENERGY_EXPORT' => '0']), $readings);
        return new CostCalculator(new InMemoryEnergyDeltaSource($deltas), new InMemoryReferenceDataSource());
    }

    private function range(string $from, string $to): TimeRange
    {
        return new TimeRange(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    private function definition(): array
    {
        return [
            'version' => 1, 'currency' => 'PLN', 'timezone' => 'UTC',
            'billingCycle' => ['anchor' => '2026-01-01', 'length' => 1, 'unit' => 'MONTH'],
            'taxRuleSets' => [['validFrom' => null, 'validTo' => null, 'rules' => [
                ['id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['ENERGY_PURCHASE', 'SUPPLIER_FIXED'], 'rate' => '0.23', 'base' => 'CURRENT_SUBTOTAL'],
            ]]],
            'periods' => [['validFrom' => null, 'validTo' => null, 'components' => [
                ['id' => 'energy', 'kind' => 'ENERGY_PURCHASE', 'category' => 'ENERGY', 'taxTreatment' => ['included' => []],
                    'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'], 'rate' => ['type' => 'CONSTANT', 'value' => '1', 'unit' => 'PLN/kWh']],
                ['id' => 'fixed', 'kind' => 'SUPPLIER_FIXED', 'category' => 'SERVICE', 'taxTreatment' => ['included' => []],
                    'quantity' => ['type' => 'PERIOD', 'period' => 'BILLING_PERIOD', 'prorate' => false],
                    'rate' => ['type' => 'CONSTANT', 'value' => '10', 'unit' => 'PLN/period']],
            ]]],
        ];
    }
}
