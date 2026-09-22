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

        foreach (array_keys($data) as $key) {
            if (!in_array($key, ['version', 'entries'], true)) {
                throw new CostPlanDefinitionException("Cost plan contains unsupported field '$key'.");
            }
        }

        $version = $data['version'] ?? null;
        if ($version !== 1) {
            throw new CostPlanDefinitionException('Cost plan version must be 1.');
        }

        $rawEntries = $data['entries'] ?? null;
        if (!is_array($rawEntries) || !array_is_list($rawEntries) || $rawEntries === []) {
            throw new CostPlanDefinitionException('Cost plan entries must be a non-empty array.');
        }

        $entries = [];
        foreach ($rawEntries as $index => $rawEntry) {
            if (!is_array($rawEntry) || array_is_list($rawEntry)) {
                throw new CostPlanDefinitionException("entries[$index] must be an object.");
            }

            $validFrom = $this->optionalDate($rawEntry['validFrom'] ?? null, "entries[$index].validFrom");
            $validTo = $this->optionalDate($rawEntry['validTo'] ?? null, "entries[$index].validTo");
            if ($validFrom !== null && $validTo !== null && $validFrom >= $validTo) {
                throw new CostPlanDefinitionException("entries[$index].validFrom must be before validTo.");
            }

            $presetId = $rawEntry['presetId'] ?? null;
            if (!is_string($presetId) || trim($presetId) === '') {
                throw new CostPlanDefinitionException("entries[$index].presetId must be a non-empty string.");
            }

            $values = $rawEntry['values'] ?? null;
            if (!is_array($values)) {
                throw new CostPlanDefinitionException("entries[$index].values must be an object.");
            }
            foreach (array_keys($values) as $key) {
                if (!is_string($key) || trim($key) === '') {
                    throw new CostPlanDefinitionException("entries[$index].values keys must be non-empty strings.");
                }
            }

            $allowedKeys = ['validFrom' => true, 'validTo' => true, 'presetId' => true, 'values' => true];
            foreach (array_keys($rawEntry) as $key) {
                if (!isset($allowedKeys[$key])) {
                    throw new CostPlanDefinitionException("entries[$index] contains unsupported field '$key'.");
                }
            }

            $entries[] = new CostPlanEntry($validFrom, $validTo, $presetId, $values);
        }

        return new CostPlanDefinition($version, $entries);
    }

    private function optionalDate(mixed $value, string $path): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new CostPlanDefinitionException("$path must be an ISO-8601 date-time string or null.");
        }
        return $this->parseDate($value, $path);
    }

    private function parseDate(string $value, string $path): \DateTimeImmutable
    {
        if (!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new CostPlanDefinitionException("$path must contain an explicit UTC offset or Z suffix.");
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new CostPlanDefinitionException("Invalid date at $path: {$e->getMessage()}", previous: $e);
        }
    }
}
