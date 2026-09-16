<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Definition\BillingCycleDefinition;
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
