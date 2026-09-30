<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Strategy\Selector;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Definition\SelectorDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;
use Supla\EnergyCostCalculator\Strategy\Selector\DefaultSelectorResolver;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class DefaultSelectorResolverTest extends TestCase
{
    public function testResolveRangeDetectsScheduleBoundary(): void
    {
        $range = new TimeRange(
            new \DateTimeImmutable('2026-01-06T05:45:00+01:00'),
            new \DateTimeImmutable('2026-01-06T06:15:00+01:00'),
        );
        $selector = $this->selector([
            ['zone' => 'NIGHT', 'days' => $this->allDays(), 'from' => '22:00', 'to' => '06:00'],
            ['zone' => 'DAY', 'days' => $this->allDays(), 'from' => '06:00', 'to' => '22:00'],
        ]);

        $this->expectException(CalculationException::class);
        $this->expectExceptionMessage('Selector result changes inside pricing interval');
        (new DefaultSelectorResolver())->resolveRange($range, $selector, $this->references($range));
    }

    public function testResolveRangeReturnsSelectionWhenNoBoundaryChangesTheZone(): void
    {
        $range = new TimeRange(
            new \DateTimeImmutable('2026-01-06T06:15:00+01:00'),
            new \DateTimeImmutable('2026-01-06T06:45:00+01:00'),
        );
        $selector = $this->selector([
            ['zone' => 'NIGHT', 'days' => $this->allDays(), 'from' => '22:00', 'to' => '06:00'],
            ['zone' => 'DAY', 'days' => $this->allDays(), 'from' => '06:00', 'to' => '22:00'],
        ]);

        self::assertSame(
            'DAY',
            (new DefaultSelectorResolver())->resolveRange($range, $selector, $this->references($range)),
        );
    }

    public function testResolveRangePreservesDstFallbackIntervalSemantics(): void
    {
        $range = new TimeRange(
            new \DateTimeImmutable('2026-10-25T02:40:00+02:00'),
            new \DateTimeImmutable('2026-10-25T02:20:00+01:00'),
        );
        $selector = $this->selector([
            ['zone' => 'SPECIAL', 'days' => ['SUN'], 'from' => '02:30', 'to' => '03:00', 'priority' => 1],
            ['zone' => 'BASE', 'days' => ['SUN'], 'from' => '00:00', 'to' => '24:00', 'priority' => 100],
        ]);

        self::assertSame(
            'SPECIAL',
            (new DefaultSelectorResolver())->resolveRange($range, $selector, $this->references($range)),
        );
    }

    public function testCompiledSchedulePreservesSeasonAndPrioritySemantics(): void
    {
        $selector = new SelectorDefinition('WEEKLY_SCHEDULE', [
            'timezone' => 'Europe/Warsaw',
            'seasons' => [
                ['id' => 'SUMMER', 'from' => '--04-01', 'to' => '--10-01'],
                ['id' => 'WINTER', 'from' => '--10-01', 'to' => '--04-01'],
            ],
            'rules' => [
                ['zone' => 'FALLBACK', 'days' => $this->allDays(), 'from' => '00:00', 'to' => '24:00', 'priority' => 100],
                ['zone' => 'SUMMER_DAY', 'season' => 'SUMMER', 'days' => $this->allDays(), 'from' => '06:00', 'to' => '22:00', 'priority' => 10],
            ],
        ]);
        $resolver = new DefaultSelectorResolver();

        $summer = new EnergyDelta(
            new \DateTimeImmutable('2026-06-15T12:00:00+02:00'),
            new \DateTimeImmutable('2026-06-15T12:15:00+02:00'),
            [],
        );
        $winter = new EnergyDelta(
            new \DateTimeImmutable('2026-01-15T12:00:00+01:00'),
            new \DateTimeImmutable('2026-01-15T12:15:00+01:00'),
            [],
        );
        $cacheRange = new TimeRange($winter->from, $summer->to);
        $references = $this->references($cacheRange);

        self::assertSame('SUMMER_DAY', $resolver->resolve($summer, $selector, $references));
        self::assertSame('FALLBACK', $resolver->resolve($winter, $selector, $references));
    }

    /** @param list<array<string, mixed>> $rules */
    private function selector(array $rules): SelectorDefinition
    {
        return new SelectorDefinition('WEEKLY_SCHEDULE', [
            'timezone' => 'Europe/Warsaw',
            'rules' => $rules,
        ]);
    }

    /** @return list<string> */
    private function allDays(): array
    {
        return ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
    }

    private function references(TimeRange $range): ReferenceDataCache
    {
        return new ReferenceDataCache(new InMemoryReferenceDataSource(), $range);
    }
}
