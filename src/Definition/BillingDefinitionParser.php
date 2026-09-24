<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Model\QuantityStrategy;
use Supla\EnergyCostCalculator\Model\QuantityType;

final class BillingDefinitionParser
{
    public function parse(string|array $input): BillingDefinition
    {
        $data = is_string($input) ? json_decode($input, true, 512, JSON_THROW_ON_ERROR) : $input;
        if (!is_array($data)) {
            throw new DefinitionException('Billing definition must be a JSON object.');
        }

        $version = (int)($data['version'] ?? 1);
        $currency = $this->requiredString($data, 'currency');
        $timezone = (string)($data['timezone'] ?? 'UTC');
        $this->assertTimezone($timezone);
        $billingCycles = $this->parseBillingCycles($data, $timezone);

        $rawPeriods = $data['periods'] ?? null;
        if (!is_array($rawPeriods) || $rawPeriods === []) {
            throw new DefinitionException('Definition.periods must be a non-empty array.');
        }

        $periods = [];
        foreach ($rawPeriods as $i => $rawPeriod) {
            if (!is_array($rawPeriod)) {
                throw new DefinitionException("periods[$i] must be an object.");
            }
            $validFrom = $this->dateOrNull($rawPeriod['validFrom'] ?? null, "periods[$i].validFrom");
            $validTo = $this->dateOrNull($rawPeriod['validTo'] ?? null, "periods[$i].validTo");
            if ($validFrom !== null && $validTo !== null && $validFrom >= $validTo) {
                throw new DefinitionException("periods[$i].validFrom must be before validTo.");
            }

            $rawComponents = $rawPeriod['components'] ?? null;
            if (!is_array($rawComponents) || $rawComponents === []) {
                throw new DefinitionException("periods[$i].components must be a non-empty array.");
            }

            $components = [];
            $componentIds = [];
            foreach ($rawComponents as $j => $rawComponent) {
                if (!is_array($rawComponent)) {
                    throw new DefinitionException("periods[$i].components[$j] must be an object.");
                }
                $component = $this->parseComponent($rawComponent, "periods[$i].components[$j]");
                if (isset($componentIds[$component->id])) {
                    throw new DefinitionException("Duplicate component id '{$component->id}' in periods[$i].");
                }
                $componentIds[$component->id] = true;
                $components[] = $component;
            }

            $periods[] = new BillingPeriodDefinition($validFrom, $validTo, $components);
        }

        usort($periods, static fn(BillingPeriodDefinition $a, BillingPeriodDefinition $b) => ($a->validFrom?->getTimestamp() ?? PHP_INT_MIN) <=> ($b->validFrom?->getTimestamp() ?? PHP_INT_MIN));
        $this->assertNoOverlappingPeriods($periods);

        return new BillingDefinition($version, $currency, $timezone, $billingCycles, $periods);
    }

    private function parseComponent(array $data, string $path): ComponentDefinition
    {
        $id = $this->requiredString($data, 'id', $path);
        $category = $this->requiredString($data, 'category', $path);

        $quantityData = $data['quantity'] ?? null;
        if (!is_array($quantityData)) {
            throw new DefinitionException("$path.quantity must be an object.");
        }
        $quantityTypeRaw = $this->requiredString($quantityData, 'type', "$path.quantity");
        $quantityType = QuantityType::tryFrom($quantityTypeRaw)
            ?? throw new DefinitionException("Unsupported quantity type '$quantityTypeRaw' at $path.quantity.type.");
        $quantityOptions = $quantityData;
        unset($quantityOptions['type']);
        $quantityStrategy = null;
        $periodInMinutes = null;
        if ($quantityType === QuantityType::PERIOD) {
            if (array_key_exists('strategy', $quantityOptions) || array_key_exists('periodInMinutes', $quantityOptions)) {
                throw new DefinitionException("$path.quantity: PERIOD quantity does not support temporal netting.");
            }
            $period = strtoupper((string)($quantityOptions['period'] ?? ''));
            if (!in_array($period, ['DAY', 'WEEK', 'MONTH', 'YEAR', 'BILLING_PERIOD'], true)) {
                throw new DefinitionException("$path.quantity.period must be DAY, WEEK, MONTH, YEAR or BILLING_PERIOD.");
            }
            $quantityOptions['period'] = $period;
            $quantityOptions['prorate'] = (bool)($quantityOptions['prorate'] ?? false);
        } else {
            $strategyRaw = $quantityOptions['strategy'] ?? null;
            $periodRaw = $quantityOptions['periodInMinutes'] ?? null;
            unset($quantityOptions['strategy'], $quantityOptions['periodInMinutes']);

            if ($strategyRaw === null && $periodRaw !== null) {
                throw new DefinitionException("$path.quantity.periodInMinutes requires quantity.strategy.");
            }
            if ($strategyRaw !== null) {
                if (!is_string($strategyRaw) || trim($strategyRaw) === '') {
                    throw new DefinitionException("$path.quantity.strategy must be a non-empty string.");
                }
                $quantityStrategy = QuantityStrategy::tryFrom(strtoupper($strategyRaw))
                    ?? throw new DefinitionException("Unsupported quantity strategy '$strategyRaw' at $path.quantity.strategy.");
                if ($quantityType !== QuantityType::ACTIVE_ENERGY_IMPORT) {
                    throw new DefinitionException("$path.quantity.strategy currently supports ACTIVE_ENERGY_IMPORT only.");
                }
                if (!is_int($periodRaw) || $periodRaw < 1) {
                    throw new DefinitionException("$path.quantity.periodInMinutes must be a positive integer when strategy is set.");
                }
                $periodInMinutes = $periodRaw;
            }
        }

        $selectorData = $data['selector'] ?? ['type' => 'ALWAYS'];
        if (!is_array($selectorData)) {
            throw new DefinitionException("$path.selector must be an object.");
        }
        $selectorType = strtoupper((string)($selectorData['type'] ?? 'ALWAYS'));
        if (!in_array($selectorType, ['ALWAYS', 'REFERENCE', 'WEEKLY_SCHEDULE'], true)) {
            throw new DefinitionException("Unsupported selector type '$selectorType' at $path.selector.type.");
        }
        $this->validateSelector($selectorType, $selectorData, "$path.selector");
        unset($selectorData['type']);

        $rateData = $data['rate'] ?? null;
        if (!is_array($rateData)) {
            throw new DefinitionException("$path.rate must be an object.");
        }
        $rateType = strtoupper($this->requiredString($rateData, 'type', "$path.rate"));
        if (!in_array($rateType, ['CONSTANT', 'ZONED', 'REFERENCE'], true)) {
            throw new DefinitionException("Unsupported rate type '$rateType' at $path.rate.type.");
        }
        $this->validateRate($rateType, $rateData, "$path.rate");
        unset($rateData['type']);

        if ($quantityType === QuantityType::PERIOD && $rateType !== 'CONSTANT') {
            throw new DefinitionException("$path: PERIOD quantity currently supports CONSTANT rate only.");
        }

        return new ComponentDefinition(
            $id,
            $category,
            new QuantityDefinition($quantityType, $quantityStrategy, $periodInMinutes, $quantityOptions),
            new SelectorDefinition($selectorType, $selectorData),
            new RateDefinition($rateType, $rateData),
        );
    }

    private function validateSelector(string $type, array $data, string $path): void
    {
        if ($type === 'REFERENCE') {
            $this->requiredString($data, 'source', $path);
            if (isset($data['mapping']) && !is_array($data['mapping'])) {
                throw new DefinitionException("$path.mapping must be an object.");
            }
        }

        if ($type === 'WEEKLY_SCHEDULE') {
            $timezone = (string)($data['timezone'] ?? 'UTC');
            $this->assertTimezone($timezone);
            if (isset($data['calendar'])) {
                $this->requiredString($data, 'calendar', $path);
            }
            if (!isset($data['rules']) || !is_array($data['rules']) || $data['rules'] === []) {
                throw new DefinitionException("$path.rules must be a non-empty array.");
            }

            $seasonIds = [];
            if (isset($data['seasons'])) {
                if (!is_array($data['seasons']) || $data['seasons'] === []) {
                    throw new DefinitionException("$path.seasons must be a non-empty array when provided.");
                }
                foreach ($data['seasons'] as $i => $season) {
                    if (!is_array($season)) {
                        throw new DefinitionException("$path.seasons[$i] must be an object.");
                    }
                    $seasonId = $this->requiredString($season, 'id', "$path.seasons[$i]");
                    if (isset($seasonIds[$seasonId])) {
                        throw new DefinitionException("Duplicate season id '$seasonId' at $path.seasons[$i].");
                    }
                    $seasonIds[$seasonId] = true;
                    $this->validateRecurringMonthDay((string)($season['from'] ?? ''), "$path.seasons[$i].from");
                    $this->validateRecurringMonthDay((string)($season['to'] ?? ''), "$path.seasons[$i].to");
                }
            }

            $usesHoliday = false;
            $allowedDays = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN', 'HOLIDAY'];
            foreach ($data['rules'] as $i => $rule) {
                if (!is_array($rule)) {
                    throw new DefinitionException("$path.rules[$i] must be an object.");
                }
                $this->requiredString($rule, 'zone', "$path.rules[$i]");
                $days = $rule['days'] ?? null;
                if (!is_array($days) || $days === []) {
                    throw new DefinitionException("$path.rules[$i].days must be a non-empty array.");
                }
                $normalizedDays = [];
                foreach ($days as $day) {
                    if (!is_string($day) || !in_array(strtoupper($day), $allowedDays, true)) {
                        $displayDay = is_scalar($day) ? (string)$day : gettype($day);
                        throw new DefinitionException("$path.rules[$i].days contains unsupported day '$displayDay'.");
                    }
                    $normalizedDays[] = strtoupper($day);
                }
                if (in_array('HOLIDAY', $normalizedDays, true) && count($normalizedDays) > 1) {
                    throw new DefinitionException("$path.rules[$i].days must not mix HOLIDAY with weekdays.");
                }
                $usesHoliday = $usesHoliday || in_array('HOLIDAY', $normalizedDays, true);

                if (isset($rule['season'])) {
                    if (!is_string($rule['season']) || $rule['season'] === '') {
                        throw new DefinitionException("$path.rules[$i].season must be a non-empty string.");
                    }
                    if ($rule['season'] !== '*' && !isset($seasonIds[$rule['season']])) {
                        throw new DefinitionException("$path.rules[$i].season references unknown season '{$rule['season']}'.");
                    }
                }
                if (isset($rule['priority']) && !is_int($rule['priority'])) {
                    throw new DefinitionException("$path.rules[$i].priority must be an integer.");
                }

                $hasShorthand = array_key_exists('from', $rule) || array_key_exists('to', $rule);
                $hasTimeRanges = array_key_exists('time_ranges', $rule);
                if ($hasShorthand && $hasTimeRanges) {
                    throw new DefinitionException("$path.rules[$i] must use either from/to or time_ranges, not both.");
                }
                if ($hasTimeRanges) {
                    if (!is_array($rule['time_ranges']) || $rule['time_ranges'] === []) {
                        throw new DefinitionException("$path.rules[$i].time_ranges must be a non-empty array.");
                    }
                    foreach ($rule['time_ranges'] as $j => $timeRange) {
                        if (!is_array($timeRange)) {
                            throw new DefinitionException("$path.rules[$i].time_ranges[$j] must be an object.");
                        }
                        $this->validateClock((string)($timeRange['from'] ?? ''), "$path.rules[$i].time_ranges[$j].from");
                        $this->validateClock((string)($timeRange['to'] ?? ''), "$path.rules[$i].time_ranges[$j].to", true);
                    }
                } elseif ($hasShorthand) {
                    $this->validateClock((string)($rule['from'] ?? ''), "$path.rules[$i].from");
                    $this->validateClock((string)($rule['to'] ?? ''), "$path.rules[$i].to", true);
                } else {
                    throw new DefinitionException("$path.rules[$i] must define from/to or time_ranges.");
                }
            }

            if ($usesHoliday && !isset($data['calendar'])) {
                throw new DefinitionException("$path.calendar is required when a WEEKLY_SCHEDULE rule uses HOLIDAY.");
            }
        }
    }

    private function validateRecurringMonthDay(string $value, string $path): void
    {
        if (!preg_match('/^--(0[1-9]|1[0-2])-(0[1-9]|[12]\\d|3[01])$/', $value)) {
            throw new DefinitionException("$path must use --MM-DD format.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', '2000-' . substr($value, 2));
        if ($date === false || $date->format('m-d') !== substr($value, 2)) {
            throw new DefinitionException("$path contains an invalid month/day.");
        }
    }

    private function validateRate(string $type, array $data, string $path): void
    {
        if ($type === 'CONSTANT') {
            $this->requiredNumericString($data, 'value', $path);
        } elseif ($type === 'ZONED') {
            if (!isset($data['rates']) || !is_array($data['rates']) || $data['rates'] === []) {
                throw new DefinitionException("$path.rates must be a non-empty object.");
            }
            foreach ($data['rates'] as $zone => $value) {
                if (!is_string($zone) || $zone === '' || !is_numeric((string)$value)) {
                    throw new DefinitionException("$path.rates contains an invalid zone/rate.");
                }
            }
        } elseif ($type === 'REFERENCE') {
            $this->requiredString($data, 'source', $path);
            if (array_key_exists('sourceUnit', $data)) {
                $this->requiredString($data, 'sourceUnit', $path);
            }
            foreach (['multiplier', 'add'] as $field) {
                if (isset($data[$field]) && !is_numeric((string)$data[$field])) {
                    throw new DefinitionException("$path.$field must be numeric.");
                }
            }
        }
    }

    private function requiredString(array $data, string $key, string $path = 'definition'): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new DefinitionException("$path.$key must be a non-empty string.");
        }
        return $value;
    }

    private function requiredNumericString(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new DefinitionException("$path.$key must be numeric.");
        }
        $value = (string)$value;
        if (!is_numeric($value)) {
            throw new DefinitionException("$path.$key must be numeric.");
        }
        return $value;
    }

    /** @return list<BillingCyclePeriodDefinition> */
    private function parseBillingCycles(array $data, string $timezone): array
    {
        if (array_key_exists('billingCycle', $data) && array_key_exists('billingCycles', $data)) {
            throw new DefinitionException('Definition cannot contain both billingCycle and billingCycles.');
        }

        if (!array_key_exists('billingCycles', $data)) {
            $cycle = $this->parseBillingCycle($data['billingCycle'] ?? null, 'definition.billingCycle', $timezone);
            return [new BillingCyclePeriodDefinition(null, null, $cycle)];
        }

        $rawPeriods = $data['billingCycles'];
        if (!is_array($rawPeriods) || $rawPeriods === []) {
            throw new DefinitionException('Definition.billingCycles must be a non-empty array.');
        }

        $periods = [];
        foreach ($rawPeriods as $i => $rawPeriod) {
            if (!is_array($rawPeriod)) {
                throw new DefinitionException("billingCycles[$i] must be an object.");
            }
            $validFrom = $this->dateOrNull($rawPeriod['validFrom'] ?? null, "billingCycles[$i].validFrom");
            $validTo = $this->dateOrNull($rawPeriod['validTo'] ?? null, "billingCycles[$i].validTo");
            if ($validFrom !== null && $validTo !== null && $validFrom >= $validTo) {
                throw new DefinitionException("billingCycles[$i].validFrom must be before validTo.");
            }

            $cycleData = $rawPeriod;
            unset($cycleData['validFrom'], $cycleData['validTo']);
            $cycle = $this->parseBillingCycle($cycleData, "billingCycles[$i]", $timezone);
            $periods[] = new BillingCyclePeriodDefinition($validFrom, $validTo, $cycle);
        }

        usort(
            $periods,
            static fn(BillingCyclePeriodDefinition $a, BillingCyclePeriodDefinition $b) =>
                ($a->validFrom?->getTimestamp() ?? PHP_INT_MIN) <=> ($b->validFrom?->getTimestamp() ?? PHP_INT_MIN),
        );
        $this->assertNoOverlappingBillingCycles($periods);
        return $periods;
    }

    private function parseBillingCycle(mixed $value, string $path, string $timezone): BillingCycleDefinition
    {
        if ($value === null) {
            return new BillingCycleDefinition(null, 1, BillingCycleUnit::MONTH);
        }
        if (!is_array($value)) {
            throw new DefinitionException("$path must be an object.");
        }

        $length = $value['length'] ?? 1;
        if (!is_int($length) || $length < 1) {
            throw new DefinitionException("$path.length must be a positive integer.");
        }

        $unitRaw = strtoupper((string)($value['unit'] ?? 'MONTH'));
        $unit = BillingCycleUnit::tryFrom($unitRaw)
            ?? throw new DefinitionException("$path.unit must be DAY, WEEK, MONTH or YEAR.");

        $anchor = $this->localDateOrNull($value['anchor'] ?? null, "$path.anchor", $timezone);
        return new BillingCycleDefinition($anchor, $length, $unit);
    }

    private function localDateOrNull(mixed $value, string $path, string $timezone): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new DefinitionException("$path must be an ISO-8601 date string or null.");
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone($timezone));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new DefinitionException("$path must be an ISO-8601 date string or null.");
        }

        return $date;
    }

    private function dateOrNull(mixed $value, string $path): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new DefinitionException("$path must be an ISO-8601 date-time string or null.");
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new DefinitionException("Invalid date at $path: {$e->getMessage()}", previous: $e);
        }
    }

    private function assertTimezone(string $timezone): void
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new DefinitionException("Invalid timezone '$timezone'.", previous: $e);
        }
    }

    /** @param list<BillingCyclePeriodDefinition> $periods */
    private function assertNoOverlappingBillingCycles(array $periods): void
    {
        for ($i = 1, $count = count($periods); $i < $count; $i++) {
            $previous = $periods[$i - 1];
            $current = $periods[$i];
            if ($previous->validTo === null || $current->validFrom === null || $current->validFrom < $previous->validTo) {
                throw new DefinitionException('Billing cycle periods must not overlap.');
            }
        }
    }

    /** @param list<BillingPeriodDefinition> $periods */
    private function assertNoOverlappingPeriods(array $periods): void
    {
        for ($i = 1, $count = count($periods); $i < $count; $i++) {
            $previous = $periods[$i - 1];
            $current = $periods[$i];
            if ($previous->validTo === null || $current->validFrom === null || $current->validFrom < $previous->validTo) {
                throw new DefinitionException('Billing periods must not overlap.');
            }
        }
    }

    private function validateClock(string $value, string $path, bool $allow24 = false): void
    {
        if ($allow24 && $value === '24:00') {
            return;
        }
        if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $value)) {
            throw new DefinitionException("$path must be HH:MM" . ($allow24 ? ' or 24:00' : '') . '.');
        }
    }
}
