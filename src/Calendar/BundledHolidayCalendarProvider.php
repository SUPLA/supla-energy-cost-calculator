<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Calendar;

use Supla\EnergyCostCalculator\Contract\HolidayCalendarProvider;
use Supla\EnergyCostCalculator\Exception\HolidayCalendarNotFoundException;
use Supla\EnergyCostCalculator\Exception\InvalidHolidayCalendarException;

final class BundledHolidayCalendarProvider implements HolidayCalendarProvider
{
    /** @var array<string, HolidayCalendar>|null */
    private ?array $calendars = null;

    public function __construct(
        private readonly ?string $calendarDirectory = null,
    ) {
    }

    public function isHoliday(string $calendarId, \DateTimeImmutable $localDate): bool
    {
        return $this->calendar($calendarId)->isHoliday($localDate);
    }

    public function calendar(string $calendarId): HolidayCalendar
    {
        $this->loadCalendars();
        if (!isset($this->calendars[$calendarId])) {
            throw new HolidayCalendarNotFoundException("Unknown holiday calendar '$calendarId'.");
        }

        return $this->calendars[$calendarId];
    }

    private function loadCalendars(): void
    {
        if ($this->calendars !== null) {
            return;
        }

        $this->calendars = [];
        $directory = $this->calendarDirectory ?? dirname(__DIR__, 2) . '/resources/calendars';
        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json') ?: [];

        foreach ($files as $file) {
            $calendar = $this->loadFile($file);
            if (isset($this->calendars[$calendar->id])) {
                throw new InvalidHolidayCalendarException("Duplicate holiday calendar id '{$calendar->id}'.");
            }
            $this->calendars[$calendar->id] = $calendar;
        }
    }

    private function loadFile(string $file): HolidayCalendar
    {
        try {
            $data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new InvalidHolidayCalendarException("Cannot parse holiday calendar '$file': {$e->getMessage()}", previous: $e);
        }

        if (!is_array($data)) {
            throw new InvalidHolidayCalendarException("Holiday calendar '$file' must contain a JSON object.");
        }

        $id = $this->requiredString($data, 'id', $file);
        $countryCode = $this->requiredString($data, 'countryCode', $file);
        $timezone = $this->requiredString($data, 'timezone', $file);
        $validFrom = $this->requiredDate($data, 'validFrom', $file);
        $validTo = $this->requiredDate($data, 'validTo', $file);
        if ($validFrom >= $validTo) {
            throw new InvalidHolidayCalendarException("Holiday calendar '$id' validFrom must be before validTo.");
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            throw new InvalidHolidayCalendarException("Holiday calendar '$id' has invalid timezone '$timezone'.", previous: $e);
        }

        $holidays = $data['holidays'] ?? null;
        if (!is_array($holidays)) {
            throw new InvalidHolidayCalendarException("Holiday calendar '$id' holidays must be an array.");
        }

        $holidayDates = [];
        foreach ($holidays as $index => $holiday) {
            if (!is_array($holiday)) {
                throw new InvalidHolidayCalendarException("Holiday calendar '$id' holidays[$index] must be an object.");
            }
            $date = $this->requiredDate($holiday, 'date', "$file holidays[$index]");
            if ($date < $validFrom || $date >= $validTo) {
                throw new InvalidHolidayCalendarException("Holiday '$date' is outside calendar '$id' coverage.");
            }
            if (isset($holidayDates[$date])) {
                throw new InvalidHolidayCalendarException("Duplicate holiday date '$date' in calendar '$id'.");
            }
            $holidayDates[$date] = true;
        }

        return new HolidayCalendar($id, $countryCode, $timezone, $validFrom, $validTo, $holidayDates);
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidHolidayCalendarException("$context.$key must be a non-empty string.");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredDate(array $data, string $key, string $context): string
    {
        $value = $this->requiredString($data, $key, $context);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidHolidayCalendarException("$context.$key must be a valid Y-m-d date.");
        }
        return $value;
    }
}
