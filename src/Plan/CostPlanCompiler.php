<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

use Supla\EnergyCostCalculator\Definition\BillingDefinition;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class CostPlanCompiler
{
    public function __construct(
        private readonly TariffPresetCatalog $catalog = new TariffPresetCatalog(),
        private readonly CostPlanDefinitionParser $planParser = new CostPlanDefinitionParser(),
        private readonly BillingDefinitionParser $definitionParser = new BillingDefinitionParser(),
        ?TariffPresetCompiler $presetCompiler = null,
    ) {
        $this->presetCompiler = $presetCompiler ?? new TariffPresetCompiler($this->catalog, $this->definitionParser);
    }

    private readonly TariffPresetCompiler $presetCompiler;

    public function compile(string|array|CostPlanDefinition $plan): BillingDefinition
    {
        return $this->definitionParser->parse($this->compileToArray($plan));
    }

    /** @return array<string, mixed> */
    public function compileToArray(string|array|CostPlanDefinition $plan): array
    {
        $plan = $plan instanceof CostPlanDefinition ? $plan : $this->planParser->parse($plan);
        return $this->compileComponentPlan($plan);
    }

    /** @return array<string, mixed> */
    private function compileComponentPlan(CostPlanDefinition $plan): array
    {
        if ($plan->periods === [] || $plan->billingCycles === [] || $plan->currency === null
            || $plan->timezone === null || !in_array($plan->priceBasis, ['NET', 'GROSS'], true)) {
            throw new CostPlanDefinitionException('Version 2 plan requires periods, billing cycles and billing metadata.');
        }
        $periods = [];
        foreach ($plan->periods as $index => $entry) {
            if (!$entry instanceof CostPlanPeriod || $entry->validFrom >= $entry->validTo || $entry->components === []) {
                throw new CostPlanDefinitionException("Invalid cost plan period $index.");
            }
            $cycleCoverage = [];
            foreach ($plan->billingCycles as $cycle) {
                $from = $this->maxDate($entry->validFrom, $this->documentDate($cycle['validFrom'] ?? null, 'billing cycle validFrom'));
                $to = $this->minDate($entry->validTo, $this->documentDate($cycle['validTo'] ?? null, 'billing cycle validTo'));
                if ($from !== null && $to !== null && $from < $to) {
                    $cycleCoverage[] = ['validFrom' => $from->format(DATE_ATOM), 'validTo' => $to->format(DATE_ATOM)];
                }
            }
            $this->assertCoverage($cycleCoverage, $entry->validFrom, $entry->validTo, "billing cycles for period $index");
            $segments = [[
                'validFrom' => $entry->validFrom->format(DATE_ATOM),
                'validTo' => $entry->validTo->format(DATE_ATOM),
                'components' => [],
            ]];
            $kinds = [];
            foreach ($entry->components as $selected) {
                if (!$selected instanceof CostPlanComponent || isset($kinds[$selected->kind->value])) {
                    throw new CostPlanDefinitionException("Period $index has an invalid or duplicate component kind.");
                }
                $kinds[$selected->kind->value] = true;
                if ($selected->presetId === null && $selected->kind->isPeriodic()) {
                    if ($selected->rate === null || $selected->per === null) {
                        throw new CostPlanDefinitionException("Period $index has an incomplete periodic component.");
                    }
                    $definition = [
                        'id' => $selected->kind->componentId(),
                        'category' => $selected->kind->category(),
                        'quantity' => ['type' => 'PERIOD', 'period' => $selected->per, 'prorate' => $selected->prorate],
                        'rate' => ['type' => 'CONSTANT', 'value' => $selected->rate],
                    ];
                    foreach ($segments as &$segment) {
                        $segment['components'][] = $definition;
                    }
                    unset($segment);
                    continue;
                }
                if ($selected->presetId === null || $selected->componentId === null) {
                    throw new CostPlanDefinitionException("Period $index has an incomplete preset component.");
                }
                if ($selected->componentId !== $selected->kind->componentId()) {
                    throw new CostPlanDefinitionException("Component '{$selected->componentId}' does not match {$selected->kind->value}.");
                }
                $preset = $this->catalog->get($selected->presetId);
                foreach (['currency' => $plan->currency, 'timezone' => $plan->timezone, 'priceBasis' => $plan->priceBasis] as $key => $expected) {
                    if (($preset->document[$key] ?? null) !== $expected) {
                        throw new CostPlanDefinitionException("Preset '{$preset->id}' has incompatible $key in period $index.");
                    }
                }
                $fragment = $this->presetCompiler->compileComponentToArray($preset, $selected->componentId, $selected->values);
                if (($fragment['version'] ?? null) !== 1) {
                    throw new CostPlanDefinitionException("Preset '{$preset->id}' has incompatible BillingDefinition version.");
                }
                $next = [];
                foreach ($segments as $segment) {
                    foreach ($fragment['periods'] as $source) {
                        $from = $this->maxDate(
                            $this->documentDate($segment['validFrom'], 'plan period validFrom'),
                            $this->documentDate($source['validFrom'] ?? null, 'preset period validFrom'),
                        );
                        $from = $this->maxDate($from, $this->documentDate($preset->document['validFrom'] ?? null, 'preset validFrom'));
                        $to = $this->minDate(
                            $this->documentDate($segment['validTo'], 'plan period validTo'),
                            $this->documentDate($source['validTo'] ?? null, 'preset period validTo'),
                        );
                        $to = $this->minDate($to, $this->documentDate($preset->document['validTo'] ?? null, 'preset validTo'));
                        if ($from === null || $to === null || $from >= $to) {
                            continue;
                        }
                        $component = $source['components'][0];
                        if (($component['id'] ?? null) !== $selected->componentId
                            || ($component['category'] ?? null) !== $selected->kind->category()
                            || ($component['quantity']['type'] ?? null) !== ($selected->kind->isPeriodic() ? 'PERIOD' : 'ACTIVE_ENERGY_IMPORT')) {
                            throw new CostPlanDefinitionException("Preset '{$preset->id}' component '{$selected->componentId}' does not match {$selected->kind->value}.");
                        }
                        $next[] = [
                            'validFrom' => $from->format(DATE_ATOM),
                            'validTo' => $to->format(DATE_ATOM),
                            'components' => [...$segment['components'], $component],
                        ];
                    }
                }
                $segments = $next;
                $this->assertCoverage($segments, $entry->validFrom, $entry->validTo, "period $index component {$selected->kind->value}");
            }
            array_push($periods, ...$segments);
        }
        usort($periods, fn(array $a, array $b) => $this->boundaryTimestamp($a['validFrom'], PHP_INT_MIN) <=> $this->boundaryTimestamp($b['validFrom'], PHP_INT_MIN));
        for ($i = 1, $count = count($periods); $i < $count; $i++) {
            if ($this->boundaryTimestamp($periods[$i - 1]['validTo'], PHP_INT_MIN)
                > $this->boundaryTimestamp($periods[$i]['validFrom'], PHP_INT_MIN)) {
                throw new CostPlanDefinitionException('Cost plan periods must not overlap.');
            }
        }
        $compiled = [
            'version' => 1,
            'currency' => $plan->currency,
            'timezone' => $plan->timezone,
            'billingCycles' => $plan->billingCycles,
            'periods' => $periods,
        ];
        $this->definitionParser->parse($compiled);
        return $compiled;
    }

    /** @param list<array<string, mixed>> $segments */
    private function assertCoverage(array $segments, \DateTimeImmutable $from, \DateTimeImmutable $to, string $context): void
    {
        usort($segments, fn(array $a, array $b) => $this->boundaryTimestamp($a['validFrom'], PHP_INT_MIN) <=> $this->boundaryTimestamp($b['validFrom'], PHP_INT_MIN));
        $cursor = $from->getTimestamp();
        foreach ($segments as $segment) {
            if ($this->boundaryTimestamp($segment['validFrom'], PHP_INT_MIN) !== $cursor) {
                throw new CostPlanDefinitionException("Preset does not cover $context continuously.");
            }
            $cursor = $this->boundaryTimestamp($segment['validTo'], PHP_INT_MIN);
        }
        if ($cursor !== $to->getTimestamp()) {
            throw new CostPlanDefinitionException("Preset does not cover $context continuously.");
        }
    }

    private function boundaryTimestamp(mixed $value, int $nullValue): int
    {
        if ($value === null) {
            return $nullValue;
        }
        if (!is_string($value)) {
            throw new CostPlanDefinitionException('Compiled billing boundary must be a date-time string or null.');
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            throw new CostPlanDefinitionException("Invalid compiled billing boundary '$value'.", previous: $e);
        }
    }

    private function documentDate(mixed $value, string $path): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new CostPlanDefinitionException("$path must be a date-time string or null.");
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new CostPlanDefinitionException("Invalid date at $path.", previous: $e);
        }
    }

    private function maxDate(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }
        return $left > $right ? $left : $right;
    }

    private function minDate(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }
        return $left < $right ? $left : $right;
    }
}
