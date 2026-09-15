<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Selector;

use Supla\EnergyCostCalculator\Calendar\BundledHolidayCalendarProvider;
use Supla\EnergyCostCalculator\Contract\HolidayCalendarProvider;
use Supla\EnergyCostCalculator\Definition\SelectorDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

final class DefaultSelectorResolver implements SelectorResolver
{
    private const DEFAULT_RULE_PRIORITY = 500;

    private const DAY_NAMES = [
        1 => 'MON',
        2 => 'TUE',
        3 => 'WED',
        4 => 'THU',
        5 => 'FRI',
        6 => 'SAT',
        7 => 'SUN',
    ];

    public function __construct(
        private readonly HolidayCalendarProvider $holidayCalendarProvider = new BundledHolidayCalendarProvider(),
    ) {
    }

    public function resolve(EnergyDelta $delta, SelectorDefinition $definition, ReferenceDataCache $references): ?string
    {
        return match ($definition->type) {
            'ALWAYS' => null,
            'REFERENCE' => $this->resolveReference($delta, $definition, $references),
            'WEEKLY_SCHEDULE' => $this->resolveWeeklySchedule($delta, $definition),
            default => throw new CalculationException("Unsupported selector {$definition->type}."),
        };
    }

    private function resolveReference(EnergyDelta $delta, SelectorDefinition $definition, ReferenceDataCache $references): string
    {
        $source = (string)$definition->config['source'];
        $rawValue = $references->series($source)->valueAt($delta->from)->value;
        $mapping = $definition->config['mapping'] ?? null;
        if (!is_array($mapping)) {
            return $rawValue;
        }
        if (!array_key_exists($rawValue, $mapping)) {
            throw new CalculationException("Reference selector '$source' returned unmapped value '$rawValue'.");
        }
        return (string)$mapping[$rawValue];
    }

    private function resolveWeeklySchedule(EnergyDelta $delta, SelectorDefinition $definition): string
    {
        $timezone = new \DateTimeZone((string)($definition->config['timezone'] ?? 'UTC'));
        $local = $delta->from->setTimezone($timezone);
        $localDay = $local->setTime(0, 0, 0);
        $rules = $definition->config['rules'];
        $seasons = $definition->config['seasons'] ?? [];
        $calendarId = (string)($definition->config['calendar'] ?? '');

        $winner = null;
        foreach (array_values($rules) as $ruleOrder => $rule) {
            foreach ($this->timeRanges($rule) as $timeRange) {
                // A range may cross midnight. Checking the current and previous local
                // day preserves the semantics from supla-cloud issue-307: the rule's
                // day/holiday/season is determined by the day on which the range starts.
                foreach ([$localDay, $localDay->modify('-1 day')] as $anchorDay) {
                    [$start, $end] = $this->buildLocalInterval($anchorDay, $timeRange);
                    if ($local < $start || $local >= $end) {
                        continue;
                    }

                    if (!$this->matchesSeason($rule['season'] ?? null, $this->resolveSeasonId($seasons, $anchorDay))) {
                        continue;
                    }
                    if (!$this->matchesDay($rule['days'], $anchorDay, $calendarId)) {
                        continue;
                    }

                    $candidate = [
                        'zone' => (string)$rule['zone'],
                        'priority' => (int)($rule['priority'] ?? self::DEFAULT_RULE_PRIORITY),
                        'ruleOrder' => $ruleOrder,
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
        }

        if ($winner !== null) {
            return $winner['zone'];
        }

        throw new CalculationException(sprintf(
            'No WEEKLY_SCHEDULE rule matched %s.',
            $local->format(DATE_ATOM),
        ));
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

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function buildLocalInterval(\DateTimeImmutable $anchorDay, array $timeRange): array
    {
        [$fromHour, $fromMinute, $fromNextDay] = $this->parseTime((string)$timeRange['from']);
        [$toHour, $toMinute, $toNextDay] = $this->parseTime((string)$timeRange['to']);

        $start = $anchorDay->setTime($fromHour, $fromMinute, 0);
        if ($fromNextDay) {
            $start = $start->modify('+1 day');
        }

        $end = $anchorDay->setTime($toHour, $toMinute, 0);
        if ($toNextDay) {
            $end = $end->modify('+1 day');
        }
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        return [$start, $end];
    }

    private function matchesDay(array $ruleDays, \DateTimeImmutable $anchorDay, string $calendarId): bool
    {
        $days = array_map('strtoupper', $ruleDays);
        $dayName = self::DAY_NAMES[(int)$anchorDay->format('N')];
        if (in_array($dayName, $days, true)) {
            return true;
        }
        if (!in_array('HOLIDAY', $days, true)) {
            return false;
        }
        if ($calendarId === '') {
            throw new CalculationException('WEEKLY_SCHEDULE with HOLIDAY rules requires a calendar.');
        }

        return $this->holidayCalendarProvider->isHoliday($calendarId, $anchorDay);
    }

    private function matchesSeason(?string $seasonRule, ?string $seasonId): bool
    {
        return $seasonRule === null || $seasonRule === '*' || $seasonRule === $seasonId;
    }

    private function resolveSeasonId(array $seasons, \DateTimeImmutable $localDay): ?string
    {
        foreach ($seasons as $season) {
            if ($this->isWithinSeason($localDay, (string)$season['from'], (string)$season['to'])) {
                return (string)$season['id'];
            }
        }

        return null;
    }

    private function isWithinSeason(\DateTimeImmutable $localDay, string $from, string $to): bool
    {
        $dayMd = $localDay->format('m-d');
        $fromMd = substr($from, 2);
        $toMd = substr($to, 2);

        if ($fromMd === $toMd) {
            return true;
        }
        if ($fromMd < $toMd) {
            return $dayMd >= $fromMd && $dayMd < $toMd;
        }

        return $dayMd >= $fromMd || $dayMd < $toMd;
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
