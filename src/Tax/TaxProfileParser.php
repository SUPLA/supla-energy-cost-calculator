<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tax;

use Supla\EnergyCostCalculator\Definition\TaxRuleDefinition;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Model\CostComponentKind;

final class TaxProfileParser
{
    public function parse(string|array $input): TaxProfile
    {
        $data = is_string($input) ? json_decode($input, true, 512, JSON_THROW_ON_ERROR) : $input;
        if (!is_array($data) || array_is_list($data) || ($data['version'] ?? null) !== 1) {
            throw new DefinitionException('Tax profile must be a version 1 JSON object.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, ['version', 'id', 'label', 'currency', 'source', 'rules'], true)) {
                throw new DefinitionException("Tax profile has unsupported property '$key'.");
            }
        }
        foreach (['id', 'label', 'currency'] as $field) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw new DefinitionException("Tax profile.$field must be a non-empty string.");
            }
        }
        if (!is_array($data['rules'] ?? null) || !array_is_list($data['rules']) || $data['rules'] === []) {
            throw new DefinitionException('Tax profile.rules must be a non-empty array.');
        }
        $rules = [];
        $ids = [];
        foreach ($data['rules'] as $index => $rule) {
            if (!is_array($rule) || array_is_list($rule)) {
                throw new DefinitionException("Tax profile.rules[$index] must be an object.");
            }
            $id = $rule['id'] ?? null;
            $type = $rule['type'] ?? null;
            $kinds = $rule['appliesToKinds'] ?? null;
            $rate = $rule['rate'] ?? null;
            if (!is_string($id) || $id === '' || isset($ids[$id]) || !in_array($type, ['PER_QUANTITY', 'PERCENTAGE'], true)
                || !is_array($kinds) || !array_is_list($kinds) || $kinds === [] || !is_string($rate) || !is_numeric($rate)) {
                throw new DefinitionException("Tax profile.rules[$index] is invalid.");
            }
            foreach (array_keys($rule) as $key) {
                if (!in_array($key, ['id', 'type', 'appliesToKinds', 'rate', 'unit', 'base'], true)) {
                    throw new DefinitionException("Tax profile.rules[$index] has unsupported property '$key'.");
                }
            }
            foreach ($kinds as $kind) {
                if (!is_string($kind) || CostComponentKind::tryFrom($kind) === null) {
                    throw new DefinitionException("Tax profile.rules[$index].appliesToKinds contains an unknown component kind.");
                }
            }
            if ($type === 'PER_QUANTITY' && ($rule['unit'] ?? null) !== $data['currency'] . '/kWh') {
                throw new DefinitionException("Tax profile.rules[$index].unit must be {$data['currency']}/kWh for PER_QUANTITY.");
            }
            if ($type === 'PERCENTAGE' && ($rule['base'] ?? null) !== 'CURRENT_SUBTOTAL') {
                throw new DefinitionException("Tax profile.rules[$index].base must be CURRENT_SUBTOTAL for PERCENTAGE.");
            }
            $ids[$id] = true;
            $rules[] = new TaxRuleDefinition($id, $type, array_values($kinds), $rate, $rule['unit'] ?? null, $rule['base'] ?? null);
        }
        return new TaxProfile(1, $data['id'], $data['label'], $data['currency'], $rules, $data);
    }
}
