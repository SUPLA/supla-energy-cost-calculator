<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Exception\CostPlanStarterNotFoundException;
use Supla\EnergyCostCalculator\Exception\InvalidCostPlanStarterException;

final class CostPlanStarterCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $entries = null;

    /** @var array<string, CostPlanStarter> */
    private array $loaded = [];

    public function __construct(
        private readonly ?string $indexFile = null,
        private readonly CostPlanDefinitionParser $parser = new CostPlanDefinitionParser(),
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function starters(): array
    {
        return array_map(function (string $id): array {
            $starter = $this->get($id);
            return [...$starter->metadata, 'revision' => $starter->revision];
        }, array_keys($this->entries()));
    }

    public function get(string $id): CostPlanStarter
    {
        if (isset($this->loaded[$id])) {
            return $this->loaded[$id];
        }
        $entry = $this->entries()[$id] ?? throw new CostPlanStarterNotFoundException("Unknown cost plan starter '$id'.");
        $components = $entry['components'] ?? null;
        if (!is_array($components) || !array_is_list($components) || $components === []) {
            throw new InvalidCostPlanStarterException("Cost plan starter '$id' must contain a non-empty components array.");
        }

        // Reuse the CostPlan parser as the single authority for component grammar. The surrounding
        // plan is validation scaffolding only; starters do not own billing cycles or period boundaries.
        try {
            $this->parser->parse([
                'version' => 2,
                'currency' => 'XXX',
                'timezone' => 'UTC',
                'priceBasis' => 'NET',
                'billingCycles' => [['length' => 1, 'unit' => 'MONTH']],
                'periods' => [['components' => $components]],
            ]);
        } catch (CostPlanDefinitionException $e) {
            throw new InvalidCostPlanStarterException(
                "Cost plan starter '$id' contains invalid components: {$e->getMessage()}",
                previous: $e,
            );
        }

        $metadata = $entry;
        unset($metadata['components']);
        return $this->loaded[$id] = new CostPlanStarter(
            $id,
            hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
            $metadata,
            $components,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        $file = $this->indexFile ?? dirname(__DIR__, 2) . '/resources/cost-plan-starters/index.json';
        $json = file_get_contents($file);
        if ($json === false) {
            throw new InvalidCostPlanStarterException("Cannot read cost plan starter catalogue '$file'.");
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCostPlanStarterException('Cannot parse cost plan starter catalogue: ' . $e->getMessage(), previous: $e);
        }
        $starters = $data['starters'] ?? null;
        if (!is_array($starters) || !array_is_list($starters)) {
            throw new InvalidCostPlanStarterException('Cost plan starter catalogue starters must be an array.');
        }
        $this->entries = [];
        foreach ($starters as $position => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new InvalidCostPlanStarterException("Cost plan starter catalogue starters[$position] must be an object.");
            }
            $id = $entry['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                throw new InvalidCostPlanStarterException("Cost plan starter catalogue starters[$position].id must be a non-empty string.");
            }
            if (isset($this->entries[$id])) {
                throw new InvalidCostPlanStarterException("Duplicate cost plan starter id '$id'.");
            }
            $this->entries[$id] = $entry;
        }
        return $this->entries;
    }
}
