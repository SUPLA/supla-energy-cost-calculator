<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Definition\BillingCycleDefinition;
use Supla\EnergyCostCalculator\Definition\BillingCyclePeriodDefinition;
use Supla\EnergyCostCalculator\Definition\BillingCycleUnit;
use Supla\EnergyCostCalculator\Engine\BillingCycleResolver;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class BillingCycleResolverTest extends TestCase
{
    public function testAnchoredMonthlyCycle(): void
    {
        $resolver = new BillingCycleResolver();
        $cycle = new BillingCycleDefinition(
            new \DateTimeImmutable('2026-01-15T00:00:00+01:00'),
            1,
            BillingCycleUnit::MONTH,
        );

        $period = $resolver->periodContaining(
            new \DateTimeImmutable('2026-02-01T12:00:00+01:00'),
            $cycle,
            'Europe/Warsaw',
        );

        self::assertSame('2026-01-15T00:00:00+01:00', $period->from->format(DATE_ATOM));
        self::assertSame('2026-02-15T00:00:00+01:00', $period->to->format(DATE_ATOM));
    }

    public function testRangeCanCoverSeveralWholeBillingPeriods(): void
    {
        $resolver = new BillingCycleResolver();
        $cycle = new BillingCycleDefinition(
            new \DateTimeImmutable('2026-01-15T00:00:00+01:00'),
            1,
            BillingCycleUnit::MONTH,
        );
        $range = new TimeRange(
            new \DateTimeImmutable('2026-01-15T00:00:00+01:00'),
            new \DateTimeImmutable('2026-03-15T00:00:00+01:00'),
        );

        $periods = $resolver->periodsOverlapping($range, $cycle, 'Europe/Warsaw');

        self::assertCount(2, $periods);
        self::assertTrue($resolver->rangeCoversWholePeriods($range, $periods));
    }

    public function testBillingCycleChangeCutsNominalPeriodIntoTransitionalPeriod(): void
    {
        $resolver = new BillingCycleResolver();
        $cycles = [
            new BillingCyclePeriodDefinition(
                null,
                new \DateTimeImmutable('2026-07-01T00:00:00+02:00'),
                new BillingCycleDefinition(
                    new \DateTimeImmutable('2026-01-15T00:00:00+01:00'),
                    1,
                    BillingCycleUnit::MONTH,
                ),
            ),
            new BillingCyclePeriodDefinition(
                new \DateTimeImmutable('2026-07-01T00:00:00+02:00'),
                null,
                new BillingCycleDefinition(
                    new \DateTimeImmutable('2026-07-01T00:00:00+02:00'),
                    1,
                    BillingCycleUnit::MONTH,
                ),
            ),
        ];
        $range = new TimeRange(
            new \DateTimeImmutable('2026-06-15T00:00:00+02:00'),
            new \DateTimeImmutable('2026-08-01T00:00:00+02:00'),
        );

        $periods = $resolver->periodsOverlappingTimeline($range, $cycles, 'Europe/Warsaw');

        self::assertCount(2, $periods);
        self::assertSame('2026-06-15T00:00:00+02:00', $periods[0]->range->from->format(DATE_ATOM));
        self::assertSame('2026-07-01T00:00:00+02:00', $periods[0]->range->to->format(DATE_ATOM));
        self::assertSame('2026-07-15T00:00:00+02:00', $periods[0]->nominalRange->to->format(DATE_ATOM));
        self::assertTrue($periods[0]->isTransitional());
        self::assertSame('2026-07-01T00:00:00+02:00', $periods[1]->range->from->format(DATE_ATOM));
        self::assertSame('2026-08-01T00:00:00+02:00', $periods[1]->range->to->format(DATE_ATOM));
        self::assertFalse($periods[1]->isTransitional());
        self::assertTrue($resolver->rangeCoversWholeResolvedPeriods($range, $periods));
    }

    public function testArbitraryWeekIsOnlyContextualizedInsideBillingPeriod(): void
    {
        $resolver = new BillingCycleResolver();
        $cycle = new BillingCycleDefinition(
            new \DateTimeImmutable('2026-01-15T00:00:00+01:00'),
            1,
            BillingCycleUnit::MONTH,
        );
        $range = new TimeRange(
            new \DateTimeImmutable('2026-01-19T00:00:00+01:00'),
            new \DateTimeImmutable('2026-01-26T00:00:00+01:00'),
        );

        $periods = $resolver->periodsOverlapping($range, $cycle, 'Europe/Warsaw');

        self::assertCount(1, $periods);
        self::assertFalse($resolver->rangeCoversWholePeriods($range, $periods));
        self::assertSame('2026-01-15T00:00:00+01:00', $periods[0]->from->format(DATE_ATOM));
        self::assertSame('2026-02-15T00:00:00+01:00', $periods[0]->to->format(DATE_ATOM));
    }
}
