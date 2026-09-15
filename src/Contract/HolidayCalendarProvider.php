<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Contract;

interface HolidayCalendarProvider
{
    /**
     * Returns whether the local calendar date is a holiday.
     *
     * The date part (Y-m-d) of $localDate is used. Implementations should
     * throw when the requested date falls outside known calendar coverage.
     */
    public function isHoliday(string $calendarId, \DateTimeImmutable $localDate): bool;
}
