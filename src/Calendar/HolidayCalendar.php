<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Calendar;

use Supla\EnergyCostCalculator\Exception\HolidayCalendarCoverageException;

final readonly class HolidayCalendar
{
    /**
     * @param array<string, true> $holidayDates Set keyed by Y-m-d.
     */
    public function __construct(
        public string $id,
        public string $countryCode,
        public string $timezone,
        public string $validFrom,
        public string $validTo,
        private array $holidayDates,
    ) {
    }

    public function isHoliday(\DateTimeImmutable $localDate): bool
    {
        $date = $localDate->format('Y-m-d');
        if ($date < $this->validFrom || $date >= $this->validTo) {
            throw new HolidayCalendarCoverageException(sprintf(
                "Holiday calendar '%s' covers [%s, %s), requested date is %s.",
                $this->id,
                $this->validFrom,
                $this->validTo,
                $date,
            ));
        }

        return isset($this->holidayDates[$date]);
    }
}
