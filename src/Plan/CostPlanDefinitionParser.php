<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;

final class CostPlanDefinitionParser
{
    public function parse(string|array $input): CostPlanDefinition
    {
        try {
            $data = is_string($input) ? json_decode($input, true, 512, JSON_THROW_ON_ERROR) : $input;
        } catch (\JsonException $e) {
            throw new CostPlanDefinitionException('Cannot parse cost plan JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new CostPlanDefinitionException('Cost plan must be a JSON object.');
        }

        if (($data['version'] ?? null) !== 2) {
            throw new CostPlanDefinitionException('Cost plan version must be 2.');
        }

        return $this->parseComponentPlan($data);
    }

    /** @param array<string, mixed> $data */
    private function parseComponentPlan(array $data): CostPlanDefinition
    {
        $this->onlyKeys($data, ['version', 'currency', 'timezone', 'priceBasis', 'billingCycles', 'periods'], 'cost plan');
        $currency = $data['currency'] ?? null;
        $timezone = $data['timezone'] ?? null;
        $basis = $data['priceBasis'] ?? null;
        if (!is_string($currency) || $currency === '' || !is_string($timezone) || $timezone === ''
            || !in_array($basis, ['NET', 'GROSS'], true)) {
            throw new CostPlanDefinitionException('Version 2 plan requires currency, timezone and NET or GROSS priceBasis.');
        }
        $cycles = $data['billingCycles'] ?? null;
        if (!is_array($cycles) || !array_is_list($cycles) || $cycles === []) {
            throw new CostPlanDefinitionException('Version 2 plan requires non-empty billingCycles.');
        }
        foreach ($cycles as $i => $cycle) {
            if (!is_array($cycle) || array_is_list($cycle)) {
                throw new CostPlanDefinitionException("billingCycles[$i] must be an object.");
            }
            $this->onlyKeys($cycle, ['validFrom', 'validTo', 'anchor', 'length', 'unit'], "billingCycles[$i]");
            foreach (['validFrom', 'validTo'] as $field) {
                if (isset($cycle[$field])) {
                    $this->parseDate($cycle[$field], "billingCycles[$i].$field");
                }
            }
            if (isset($cycle['anchor'])) {
                $this->parseCalendarDate($cycle['anchor'], "billingCycles[$i].anchor");
            }
            if (!isset($cycle['length']) || !is_int($cycle['length']) || $cycle['length'] < 1
                || !in_array($cycle['unit'] ?? null, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true)) {
                throw new CostPlanDefinitionException("billingCycles[$i] requires a positive length and valid unit.");
            }
        }

        $rawPeriods = $data['periods'] ?? null;
        if (!is_array($rawPeriods) || !array_is_list($rawPeriods) || $rawPeriods === []) {
            throw new CostPlanDefinitionException('Version 2 plan requires non-empty periods.');
        }
        $periods = [];
        foreach ($rawPeriods as $i => $rawPeriod) {
            if (!is_array($rawPeriod) || array_is_list($rawPeriod)) {
                throw new CostPlanDefinitionException("periods[$i] must be an object.");
            }
            $this->onlyKeys($rawPeriod, ['validFrom', 'validTo', 'components'], "periods[$i]");
            $from = $this->parseDate($rawPeriod['validFrom'] ?? null, "periods[$i].validFrom");
            $to = $this->parseDate($rawPeriod['validTo'] ?? null, "periods[$i].validTo");
            if ($from !== null && $to !== null && $from >= $to) {
                throw new CostPlanDefinitionException("periods[$i].validFrom must be before validTo.");
            }
            $rawComponents = $rawPeriod['components'] ?? null;
            if (!is_array($rawComponents) || !array_is_list($rawComponents) || $rawComponents === []) {
                throw new CostPlanDefinitionException("periods[$i].components must be a non-empty array.");
            }
            $components = [];
            $componentIds = [];
            foreach ($rawComponents as $j => $raw) {
                $path = "periods[$i].components[$j]";
                if (!is_array($raw) || array_is_list($raw)) {
                    throw new CostPlanDefinitionException("$path must be an object.");
                }
                $kind = is_string($raw['kind'] ?? null) ? CostComponentKind::tryFrom($raw['kind']) : null;
                if ($kind === null) {
                    throw new CostPlanDefinitionException("$path has unknown component kind.");
                }
                if (!array_key_exists('presetId', $raw) && $kind->isPeriodic()) {
                    $this->onlyKeys($raw, ['kind', 'rate', 'per', 'prorate'], $path);
                    $rate = $raw['rate'] ?? null;
                    $per = $raw['per'] ?? null;
                    $prorate = $raw['prorate'] ?? false;
                    if (!is_string($rate) || !preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $rate)
                        || !in_array($per, ['DAY', 'WEEK', 'MONTH', 'YEAR', 'BILLING_PERIOD'], true)
                        || !is_bool($prorate)) {
                        throw new CostPlanDefinitionException("$path requires decimal rate, valid per and boolean prorate.");
                    }
                    $componentId = $kind->componentId();
                    if (isset($componentIds[$componentId])) {
                        throw new CostPlanDefinitionException("$path duplicates componentId '$componentId'.");
                    }
                    $componentIds[$componentId] = true;
                    $components[] = new CostPlanComponent($kind, rate: $rate, per: $per, prorate: $prorate);
                } else {
                    $this->onlyKeys($raw, ['kind', 'presetId', 'componentId', 'values'], $path);
                    $presetId = $raw['presetId'] ?? null;
                    $componentId = $raw['componentId'] ?? null;
                    $values = $raw['values'] ?? null;
                    if (!is_string($presetId) || trim($presetId) === '' || !is_string($componentId)
                        || trim($componentId) === '' || !is_array($values)) {
                        throw new CostPlanDefinitionException("$path requires presetId, componentId and values.");
                    }
                    if (isset($componentIds[$componentId])) {
                        throw new CostPlanDefinitionException("$path duplicates componentId '$componentId'.");
                    }
                    $componentIds[$componentId] = true;
                    foreach (array_keys($values) as $key) {
                        if (!is_string($key) || trim($key) === '') {
                            throw new CostPlanDefinitionException("$path.values keys must be non-empty strings.");
                        }
                    }
                    $components[] = new CostPlanComponent($kind, $presetId, $componentId, $values);
                }
            }
            $periods[] = new CostPlanPeriod($from, $to, $components);
        }
        $this->assertContinuousPeriods($periods);

        return new CostPlanDefinition($cycles, $currency, $timezone, $basis, $periods);
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private function onlyKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new CostPlanDefinitionException("$path contains unsupported field '$key'.");
            }
        }
    }

    /** @param list<CostPlanPeriod> $periods */
    private function assertContinuousPeriods(array $periods): void
    {
        if (count($periods) === 1) {
            return;
        }

        foreach ($periods as $i => $period) {
            if ($i > 0 && $i < count($periods) - 1 && ($period->validFrom === null || $period->validTo === null)) {
                throw new CostPlanDefinitionException("periods[$i] must define validFrom and validTo.");
            }
            if ($i === 0 && $period->validTo === null) {
                throw new CostPlanDefinitionException('periods[0].validTo is required when multiple periods are defined.');
            }
            if ($i === count($periods) - 1 && $period->validFrom === null) {
                throw new CostPlanDefinitionException("periods[$i].validFrom is required when multiple periods are defined.");
            }
            if ($i > 0 && $periods[$i - 1]->validTo != $period->validFrom) {
                throw new CostPlanDefinitionException('Cost plan periods must be contiguous and ordered.');
            }
        }
    }

    private function parseDate(mixed $value, string $path): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new CostPlanDefinitionException("$path must contain an explicit UTC offset or Z suffix.");
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new CostPlanDefinitionException("Invalid date at $path: {$e->getMessage()}", previous: $e);
        }
    }

    private function parseCalendarDate(mixed $value, string $path): void
    {
        if (!is_string($value)) {
            throw new CostPlanDefinitionException("$path must be an ISO-8601 date.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new CostPlanDefinitionException("$path must be an ISO-8601 date.");
        }
    }
}
