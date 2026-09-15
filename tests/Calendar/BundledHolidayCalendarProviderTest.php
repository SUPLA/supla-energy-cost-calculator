<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Calendar;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Calendar\BundledHolidayCalendarProvider;
use Supla\EnergyCostCalculator\Exception\HolidayCalendarCoverageException;

final class BundledHolidayCalendarProviderTest extends TestCase
{
    private BundledHolidayCalendarProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new BundledHolidayCalendarProvider();
    }

    public function testChristmasEveBecomesHolidayStartingIn2025(): void
    {
        self::assertFalse($this->provider->isHoliday('PL_PUBLIC_HOLIDAYS', new \DateTimeImmutable('2024-12-24')));
        self::assertTrue($this->provider->isHoliday('PL_PUBLIC_HOLIDAYS', new \DateTimeImmutable('2025-12-24')));
    }

    public function testMovableAndFixedPolishHolidaysAreBundled(): void
    {
        self::assertTrue($this->provider->isHoliday('PL_PUBLIC_HOLIDAYS', new \DateTimeImmutable('2026-04-06'))); // Easter Monday
        self::assertTrue($this->provider->isHoliday('PL_PUBLIC_HOLIDAYS', new \DateTimeImmutable('2026-05-01'))); // Labour Day
    }

    public function testOneOff12November2018HolidayIsBundled(): void
    {
        self::assertTrue($this->provider->isHoliday('PL_PUBLIC_HOLIDAYS', new \DateTimeImmutable('2018-11-12')));
    }

    public function testDateOutsideCalendarCoverageIsRejected(): void
    {
        $this->expectException(HolidayCalendarCoverageException::class);
        $this->provider->isHoliday('PL_PUBLIC_HOLIDAYS', new \DateTimeImmutable('2031-01-01'));
    }
}
