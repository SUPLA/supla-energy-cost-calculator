<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Strategy\Selector;

use Supla\EnergyCostCalculator\Definition\SelectorDefinition;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;

final class DefaultSelectorResolver implements SelectorResolver
{
    private const DAY_MAP = [
        'MON' => 1,
        'TUE' => 2,
        'WED' => 3,
        'THU' => 4,
        'FRI' => 5,
        'SAT' => 6,
        'SUN' => 7,
    ];

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
        $dayNumber = (int)$local->format('N');
        $minute = ((int)$local->format('G') * 60) + (int)$local->format('i');

        foreach ($definition->config['rules'] as $rule) {
            $days = array_map(static fn(string $d) => self::DAY_MAP[strtoupper($d)] ?? 0, $rule['days']);
            if (!in_array($dayNumber, $days, true)) {
                continue;
            }
            $from = $this->clockToMinute((string)$rule['from']);
            $to = $this->clockToMinute((string)$rule['to']);
            if ($minute >= $from && $minute < $to) {
                return (string)$rule['zone'];
            }
        }

        throw new CalculationException(sprintf(
            'No WEEKLY_SCHEDULE rule matched %s.',
            $local->format(DATE_ATOM),
        ));
    }

    private function clockToMinute(string $clock): int
    {
        if ($clock === '24:00') {
            return 1440;
        }
        [$hour, $minute] = array_map('intval', explode(':', $clock, 2));
        return $hour * 60 + $minute;
    }
}
