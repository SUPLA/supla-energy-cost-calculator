<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Selector;

use Supla\EnergyCostCalculator\Calendar\BundledHolidayCalendarProvider;
use Supla\EnergyCostCalculator\Contract\HolidayCalendarProvider;
use Supla\EnergyCostCalculator\Definition\SelectorDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

final class DefaultSelectorResolver implements SelectorResolver, TimeRangeSelectorResolver
{
    private const DEFAULT_RULE_PRIORITY = 500;
    private const DATED_SCHEDULE_CACHE_LIMIT = 4;

    private const DAY_BITS = [
        'MON' => 1 << 0,
        'TUE' => 1 << 1,
        'WED' => 1 << 2,
        'THU' => 1 << 3,
        'FRI' => 1 << 4,
        'SAT' => 1 << 5,
        'SUN' => 1 << 6,
    ];

    /**
     * @var \WeakMap<SelectorDefinition, array{
     *     timezone: \DateTimeZone,
     *     calendarId: string,
     *     seasons: list<array{id: string, from: int, to: int}>,
     *     rules: list<array{
     *         zone: string,
     *         priority: int,
     *         ruleOrder: int,
     *         season: ?string,
     *         weekdayMask: int,
     *         holiday: bool,
     *         fromHour: int,
     *         fromMinute: int,
     *         fromNextDay: bool,
     *         toHour: int,
     *         toMinute: int,
     *         toNextDay: bool
     *     }>
     * }>
     */
    private \WeakMap $compiledWeeklySchedules;

    /**
     * @var \WeakMap<SelectorDefinition, array<string, array{
     *     anchorDay: \DateTimeImmutable,
     *     seasonId: ?string,
     *     weekdayBit: int,
     *     intervals: list<array{
     *         zone: string,
     *         priority: int,
     *         ruleOrder: int,
     *         season: ?string,
     *         weekdayMask: int,
     *         holiday: bool,
     *         start: \DateTimeImmutable,
     *         end: \DateTimeImmutable
     *     }>
     * }>>
     */
    private \WeakMap $datedWeeklySchedules;

    public function __construct(
        private readonly HolidayCalendarProvider $holidayCalendarProvider = new BundledHolidayCalendarProvider(),
    ) {
        $this->compiledWeeklySchedules = new \WeakMap();
        $this->datedWeeklySchedules = new \WeakMap();
    }

    public function resolve(EnergyDelta $delta, SelectorDefinition $definition, ReferenceDataCache $references): ?string
    {
        return $this->resolveAt($delta->from, null, $definition, $references);
    }

    public function resolveRange(
        TimeRange $range,
        SelectorDefinition $definition,
        ReferenceDataCache $references,
    ): ?string {
        $selection = $this->resolveAt($range->from, $range, $definition, $references);
        if ($definition->type !== 'WEEKLY_SCHEDULE') {
            return $selection;
        }

        foreach ($this->weeklyScheduleBoundaries($range, $definition) as $boundary) {
            if ($this->resolveWeeklySchedule($boundary, $definition) !== $selection) {
                throw new CalculationException(sprintf(
                    'Selector result changes inside pricing interval %s..%s.',
                    $range->from->format(DATE_ATOM),
                    $range->to->format(DATE_ATOM),
                ));
            }
        }

        return $selection;
    }

    private function resolveAt(
        \DateTimeImmutable $timestamp,
        ?TimeRange $range,
        SelectorDefinition $definition,
        ReferenceDataCache $references,
    ): ?string {
        return match ($definition->type) {
            'ALWAYS' => null,
            'REFERENCE' => $this->resolveReference($timestamp, $range, $definition, $references),
            'WEEKLY_SCHEDULE' => $this->resolveWeeklySchedule($timestamp, $definition),
            default => throw new CalculationException("Unsupported selector {$definition->type}."),
        };
    }

    private function resolveReference(
        \DateTimeImmutable $timestamp,
        ?TimeRange $range,
        SelectorDefinition $definition,
        ReferenceDataCache $references,
    ): string {
        $source = (string)$definition->config['source'];
        $interval = $references->series($source)->valueAt($timestamp);
        if ($range !== null && $interval->to < $range->to) {
            throw new CalculationException(sprintf(
                "Reference selector '%s' changes inside pricing interval %s..%s.",
                $source,
                $range->from->format(DATE_ATOM),
                $range->to->format(DATE_ATOM),
            ));
        }
        $rawValue = $interval->value;
        $mapping = $definition->config['mapping'] ?? null;
        if (!is_array($mapping)) {
            return $rawValue;
        }
        if (!array_key_exists($rawValue, $mapping)) {
            throw new CalculationException("Reference selector '$source' returned unmapped value '$rawValue'.");
        }
        return (string)$mapping[$rawValue];
    }

    private function resolveWeeklySchedule(\DateTimeImmutable $timestamp, SelectorDefinition $definition): string
    {
        $schedule = $this->compiledWeeklySchedule($definition);
        $local = $timestamp->setTimezone($schedule['timezone']);
        $localDay = $local->setTime(0, 0, 0);
        $datedSchedules = [
            $this->datedWeeklySchedule($definition, $localDay),
            $this->datedWeeklySchedule($definition, $localDay->modify('-1 day')),
        ];
        $holidayByDate = [];

        $winner = null;
        foreach ($datedSchedules as $datedSchedule) {
            foreach ($datedSchedule['intervals'] as $rule) {
                if ($local < $rule['start'] || $local >= $rule['end']) {
                    continue;
                }
                if (!$this->matchesSeason($rule['season'], $datedSchedule['seasonId'])) {
                    continue;
                }
                if (!$this->matchesCompiledDay($rule, $datedSchedule, $schedule['calendarId'], $holidayByDate)) {
                    continue;
                }

                $candidate = [
                    'zone' => $rule['zone'],
                    'priority' => $rule['priority'],
                    'ruleOrder' => $rule['ruleOrder'],
                ];
                if (
                    $winner === null
                    || $candidate['priority'] < $winner['priority']
                    || ($candidate['priority'] === $winner['priority'] && $candidate['ruleOrder'] < $winner['ruleOrder'])
                ) {
                    $winner = $candidate;
                }
            }
        }

        if ($winner !== null) {
            return $winner['zone'];
        }

        throw new CalculationException(sprintf(
            'No WEEKLY_SCHEDULE rule matched %s.',
            $local->format(DATE_ATOM),
        ));
    }

    /**
     * @return array{
     *     timezone: \DateTimeZone,
     *     calendarId: string,
     *     seasons: list<array{id: string, from: int, to: int}>,
     *     rules: list<array{
     *         zone: string,
     *         priority: int,
     *         ruleOrder: int,
     *         season: ?string,
     *         weekdayMask: int,
     *         holiday: bool,
     *         fromHour: int,
     *         fromMinute: int,
     *         fromNextDay: bool,
     *         toHour: int,
     *         toMinute: int,
     *         toNextDay: bool
     *     }>
     * }
     */
    private function compiledWeeklySchedule(SelectorDefinition $definition): array
    {
        if (isset($this->compiledWeeklySchedules[$definition])) {
            return $this->compiledWeeklySchedules[$definition];
        }

        $seasons = [];
        foreach ($definition->config['seasons'] ?? [] as $season) {
            $seasons[] = [
                'id' => (string)$season['id'],
                'from' => $this->monthDayNumber((string)$season['from']),
                'to' => $this->monthDayNumber((string)$season['to']),
            ];
        }

        $rules = [];
        foreach (array_values($definition->config['rules']) as $ruleOrder => $rule) {
            $weekdayMask = 0;
            $holiday = false;
            foreach ($rule['days'] as $day) {
                $day = strtoupper((string)$day);
                if ($day === 'HOLIDAY') {
                    $holiday = true;
                    continue;
                }
                $weekdayMask |= self::DAY_BITS[$day] ?? 0;
            }

            foreach ($this->timeRanges($rule) as $timeRange) {
                [$fromHour, $fromMinute, $fromNextDay] = $this->parseTime((string)$timeRange['from']);
                [$toHour, $toMinute, $toNextDay] = $this->parseTime((string)$timeRange['to']);
                $rules[] = [
                    'zone' => (string)$rule['zone'],
                    'priority' => (int)($rule['priority'] ?? self::DEFAULT_RULE_PRIORITY),
                    'ruleOrder' => $ruleOrder,
                    'season' => isset($rule['season']) ? (string)$rule['season'] : null,
                    'weekdayMask' => $weekdayMask,
                    'holiday' => $holiday,
                    'fromHour' => $fromHour,
                    'fromMinute' => $fromMinute,
                    'fromNextDay' => $fromNextDay,
                    'toHour' => $toHour,
                    'toMinute' => $toMinute,
                    'toNextDay' => $toNextDay,
                ];
            }
        }

        $compiled = [
            'timezone' => new \DateTimeZone((string)($definition->config['timezone'] ?? 'UTC')),
            'calendarId' => (string)($definition->config['calendar'] ?? ''),
            'seasons' => $seasons,
            'rules' => $rules,
        ];
        $this->compiledWeeklySchedules[$definition] = $compiled;
        return $compiled;
    }

    /**
     * @return array{
     *     anchorDay: \DateTimeImmutable,
     *     seasonId: ?string,
     *     weekdayBit: int,
     *     intervals: list<array{
     *         zone: string,
     *         priority: int,
     *         ruleOrder: int,
     *         season: ?string,
     *         weekdayMask: int,
     *         holiday: bool,
     *         start: \DateTimeImmutable,
     *         end: \DateTimeImmutable
     *     }>
     * }
     */
    private function datedWeeklySchedule(SelectorDefinition $definition, \DateTimeImmutable $anchorDay): array
    {
        $schedule = $this->compiledWeeklySchedule($definition);
        $anchorDay = $anchorDay->setTimezone($schedule['timezone'])->setTime(0, 0, 0);
        $date = $anchorDay->format('Y-m-d');
        $cache = $this->datedWeeklySchedules[$definition] ?? [];
        if (isset($cache[$date])) {
            return $cache[$date];
        }

        $intervals = [];
        foreach ($schedule['rules'] as $rule) {
            $start = $anchorDay->setTime($rule['fromHour'], $rule['fromMinute'], 0);
            if ($rule['fromNextDay']) {
                $start = $start->modify('+1 day');
            }

            $end = $anchorDay->setTime($rule['toHour'], $rule['toMinute'], 0);
            if ($rule['toNextDay']) {
                $end = $end->modify('+1 day');
            }
            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }

            $intervals[] = [
                'zone' => $rule['zone'],
                'priority' => $rule['priority'],
                'ruleOrder' => $rule['ruleOrder'],
                'season' => $rule['season'],
                'weekdayMask' => $rule['weekdayMask'],
                'holiday' => $rule['holiday'],
                'start' => $start,
                'end' => $end,
            ];
        }

        $dated = [
            'anchorDay' => $anchorDay,
            'seasonId' => $this->resolveCompiledSeasonId($schedule['seasons'], $anchorDay),
            'weekdayBit' => 1 << ((int)$anchorDay->format('N') - 1),
            'intervals' => $intervals,
        ];
        $cache[$date] = $dated;
        while (count($cache) > self::DATED_SCHEDULE_CACHE_LIMIT) {
            unset($cache[array_key_first($cache)]);
        }
        $this->datedWeeklySchedules[$definition] = $cache;
        return $dated;
    }

    /** @return list<\DateTimeImmutable> */
    private function weeklyScheduleBoundaries(TimeRange $range, SelectorDefinition $definition): array
    {
        $schedule = $this->compiledWeeklySchedule($definition);
        $fromTimestamp = $range->from->getTimestamp();
        $toTimestamp = $range->to->getTimestamp();
        $boundaries = [];

        $firstAnchorDay = $range->from->setTimezone($schedule['timezone'])->setTime(0, 0, 0)->modify('-1 day');
        $lastAnchorDay = $range->to->setTimezone($schedule['timezone'])->setTime(0, 0, 0);
        for ($day = $firstAnchorDay; $day <= $lastAnchorDay; $day = $day->modify('+1 day')) {
            foreach ($this->datedWeeklySchedule($definition, $day)['intervals'] as $rule) {
                foreach ([$rule['start'], $rule['end']] as $boundary) {
                    $timestamp = $boundary->getTimestamp();
                    if ($timestamp > $fromTimestamp && $timestamp < $toTimestamp) {
                        $boundaries[$timestamp] = $boundary;
                    }
                }
            }
        }

        $transitions = $schedule['timezone']->getTransitions($fromTimestamp, $toTimestamp);
        if (is_array($transitions)) {
            foreach ($transitions as $transition) {
                $timestamp = (int)$transition['ts'];
                if ($timestamp > $fromTimestamp && $timestamp < $toTimestamp) {
                    $boundaries[$timestamp] = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($schedule['timezone']);
                }
            }
        }

        ksort($boundaries, SORT_NUMERIC);
        return array_values($boundaries);
    }

    /** @return list<array{from: string, to: string}> */
    private function timeRanges(array $rule): array
    {
        if (isset($rule['time_ranges'])) {
            return array_values($rule['time_ranges']);
        }

        // Backwards-compatible shorthand used by the initial package version.
        return [[
            'from' => (string)$rule['from'],
            'to' => (string)$rule['to'],
        ]];
    }

    /**
     * @param array{weekdayMask: int, holiday: bool} $rule
     * @param array{anchorDay: \DateTimeImmutable, weekdayBit: int} $datedSchedule
     * @param array<string, bool> $holidayByDate
     */
    private function matchesCompiledDay(array $rule, array $datedSchedule, string $calendarId, array &$holidayByDate): bool
    {
        if (($rule['weekdayMask'] & $datedSchedule['weekdayBit']) !== 0) {
            return true;
        }
        if (!$rule['holiday']) {
            return false;
        }
        if ($calendarId === '') {
            throw new CalculationException('WEEKLY_SCHEDULE with HOLIDAY rules requires a calendar.');
        }

        $anchorDay = $datedSchedule['anchorDay'];
        $date = $anchorDay->format('Y-m-d');
        return $holidayByDate[$date] ??= $this->holidayCalendarProvider->isHoliday($calendarId, $anchorDay);
    }

    private function matchesSeason(?string $seasonRule, ?string $seasonId): bool
    {
        return $seasonRule === null || $seasonRule === '*' || $seasonRule === $seasonId;
    }

    /** @param list<array{id: string, from: int, to: int}> $seasons */
    private function resolveCompiledSeasonId(array $seasons, \DateTimeImmutable $localDay): ?string
    {
        $day = ((int)$localDay->format('n') * 100) + (int)$localDay->format('j');
        foreach ($seasons as $season) {
            if ($season['from'] === $season['to']) {
                return $season['id'];
            }
            if ($season['from'] < $season['to']) {
                if ($day >= $season['from'] && $day < $season['to']) {
                    return $season['id'];
                }
                continue;
            }
            if ($day >= $season['from'] || $day < $season['to']) {
                return $season['id'];
            }
        }

        return null;
    }

    private function monthDayNumber(string $value): int
    {
        return ((int)substr($value, 2, 2) * 100) + (int)substr($value, 5, 2);
    }

    /** @return array{0: int, 1: int, 2: bool} */
    private function parseTime(string $time): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $time, 2));
        if ($hour === 24 && $minute === 0) {
            return [0, 0, true];
        }

        return [$hour, $minute, false];
    }
}
