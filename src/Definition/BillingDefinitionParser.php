<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Definition;

use Supla\EnergyCostCalculator\Exception\DefinitionException;
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

        return new BillingDefinition($version, $currency, $timezone, $periods);
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
        if ($quantityType === QuantityType::PERIOD) {
            $period = strtoupper((string)($quantityOptions['period'] ?? ''));
            if (!in_array($period, ['DAY', 'WEEK', 'MONTH'], true)) {
                throw new DefinitionException("$path.quantity.period must be DAY, WEEK or MONTH.");
            }
            $quantityOptions['period'] = $period;
            $quantityOptions['prorate'] = (bool)($quantityOptions['prorate'] ?? false);
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
            new QuantityDefinition($quantityType, $quantityOptions),
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
                $this->validateClock((string)($rule['from'] ?? ''), "$path.rules[$i].from");
                $this->validateClock((string)($rule['to'] ?? ''), "$path.rules[$i].to", true);
            }

            if ($usesHoliday && !isset($data['calendar'])) {
                throw new DefinitionException("$path.calendar is required when a WEEKLY_SCHEDULE rule uses HOLIDAY.");
            }
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
