<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

use Supla\EnergyCostCalculator\Definition\BillingDefinition;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Model\CostComponentKind;
use Supla\EnergyCostCalculator\Preset\TariffPreset;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;
use Supla\EnergyCostCalculator\Tax\TaxContext;
use Supla\EnergyCostCalculator\Tax\TaxProfileResolver;

final class CostPlanCompiler
{
    public function __construct(
        private readonly TariffPresetCatalog $catalog = new TariffPresetCatalog(),
        private readonly CostPlanDefinitionParser $planParser = new CostPlanDefinitionParser(),
        private readonly BillingDefinitionParser $definitionParser = new BillingDefinitionParser(),
        private readonly TaxProfileResolver $taxProfileResolver = new TaxProfileResolver(),
        ?TariffPresetCompiler $presetCompiler = null,
    ) {
        $this->presetCompiler = $presetCompiler ?? new TariffPresetCompiler($this->catalog);
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
        if ($plan->periods === [] || $plan->billingCycles === []) {
            throw new CostPlanDefinitionException('Version 2 plan requires periods, billing cycles and billing metadata.');
        }
        $this->assertContinuousPlanPeriods($plan->periods);
        $periods = [];
        $presetContexts = [];
        foreach ($plan->periods as $index => $entry) {
            if (!$entry instanceof CostPlanPeriod
                || ($entry->validFrom !== null && $entry->validTo !== null && $entry->validFrom >= $entry->validTo)
                || $entry->components === []) {
                throw new CostPlanDefinitionException("Invalid cost plan period $index.");
            }
            $cycleCoverage = [];
            foreach ($plan->billingCycles as $cycle) {
                $from = $this->maxDate($entry->validFrom, $this->documentDate($cycle['validFrom'] ?? null, 'billing cycle validFrom'));
                $to = $this->minDate($entry->validTo, $this->documentDate($cycle['validTo'] ?? null, 'billing cycle validTo'));
                if ($from === null || $to === null || $from < $to) {
                    $cycleCoverage[] = [
                        'validFrom' => $from?->format(DATE_ATOM),
                        'validTo' => $to?->format(DATE_ATOM),
                    ];
                }
            }
            $cycleCoverageFrom = $entry->validFrom
                ?? $this->documentDate($cycleCoverage[0]['validFrom'] ?? null, 'billing cycle coverage validFrom');
            $cycleCoverageTo = $entry->validTo
                ?? $this->documentDate($cycleCoverage[array_key_last($cycleCoverage)]['validTo'] ?? null, 'billing cycle coverage validTo');
            $this->assertCoverage($cycleCoverage, $cycleCoverageFrom, $cycleCoverageTo, "billing cycles for period $index");
            $segments = [[
                'validFrom' => $entry->validFrom?->format(DATE_ATOM),
                'validTo' => $entry->validTo?->format(DATE_ATOM),
                'components' => [],
            ]];
            $componentIds = [];
            foreach ($entry->components as $selected) {
                if (!$selected instanceof CostPlanComponent) {
                    throw new CostPlanDefinitionException("Period $index has an invalid component.");
                }
                $componentIdentity = $selected->componentId ?? $selected->kind->componentId();
                if (isset($componentIds[$componentIdentity])) {
                    throw new CostPlanDefinitionException("Period $index contains duplicate componentId '$componentIdentity'.");
                }
                $componentIds[$componentIdentity] = true;
                if ($selected->presetId === null && $selected->kind->isPeriodic()) {
                    if ($selected->rate === null || $selected->per === null) {
                        throw new CostPlanDefinitionException("Period $index has an incomplete periodic component.");
                    }
                    $definition = [
                        'id' => $componentIdentity,
                        'kind' => $selected->kind->value,
                        'category' => $selected->kind->category(),
                        'quantity' => ['type' => 'PERIOD', 'period' => $selected->per, 'prorate' => $selected->prorate],
                        'rate' => ['type' => 'CONSTANT', 'value' => $selected->rate],
                        'taxTreatment' => $selected->taxTreatment,
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
                $preset = $this->catalog->get($selected->presetId);
                $presetContexts[$preset->id] = $this->presetTaxContext($preset);
                foreach (['currency' => $plan->currency, 'timezone' => $plan->timezone] as $key => $expected) {
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
                    $sourcePeriods = $fragment['periods'];
                    foreach ($sourcePeriods as $sourceIndex => $source) {
                        $from = $sourceIndex === 0
                            ? $this->documentDate($segment['validFrom'], 'plan period validFrom')
                            : $this->maxDate(
                                $this->documentDate($segment['validFrom'], 'plan period validFrom'),
                                $this->documentDate($source['validFrom'] ?? null, 'preset period validFrom'),
                            );
                        $to = $sourceIndex === array_key_last($sourcePeriods)
                            ? $this->documentDate($segment['validTo'], 'plan period validTo')
                            : $this->minDate(
                                $this->documentDate($segment['validTo'], 'plan period validTo'),
                                $this->documentDate($source['validTo'] ?? null, 'preset period validTo'),
                            );
                        if ($from !== null && $to !== null && $from >= $to) {
                            continue;
                        }
                        $component = $source['components'][0];
                        if (($component['id'] ?? null) !== $selected->componentId
                            || ($component['category'] ?? null) !== $selected->kind->category()
                            || ($component['quantity']['type'] ?? null) !== ($selected->kind->isPeriodic() ? 'PERIOD' : 'ACTIVE_ENERGY_IMPORT')) {
                            throw new CostPlanDefinitionException("Preset '{$preset->id}' component '{$selected->componentId}' does not match {$selected->kind->value}.");
                        }
                        $next[] = [
                            'validFrom' => $from?->format(DATE_ATOM),
                            'validTo' => $to?->format(DATE_ATOM),
                            'components' => [...$segment['components'], $component],
                        ];
                    }
                }
                $segments = $next;
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

        $taxContext = $this->resolveTaxContext($plan->taxContext, $presetContexts);
        try {
            $assignments = $this->taxProfileResolver->resolveTimeline($taxContext, $plan->currency);
        } catch (DefinitionException $e) {
            throw new CostPlanDefinitionException($e->getMessage(), previous: $e);
        }
        $compiled = [
            'version' => 1,
            'currency' => $plan->currency,
            'timezone' => $plan->timezone,
            'billingCycles' => $plan->billingCycles,
            'taxRuleSets' => $this->compileTaxRuleSets($assignments),
            'periods' => $periods,
        ];
        $this->definitionParser->parse($compiled);
        return $compiled;
    }

    /** @param array<string, TaxContext> $presetContexts */
    private function resolveTaxContext(?TaxContext $explicit, array $presetContexts): TaxContext
    {
        $resolved = $explicit;
        foreach ($presetContexts as $presetId => $context) {
            if ($resolved === null) {
                $resolved = $context;
                continue;
            }
            if (!$resolved->equals($context)) {
                throw new CostPlanDefinitionException(sprintf(
                    "Conflicting tax contexts in CostPlan: %s/%s conflicts with preset '%s' (%s/%s).",
                    $resolved->jurisdiction,
                    $resolved->customerClass,
                    $presetId,
                    $context->jurisdiction,
                    $context->customerClass,
                ));
            }
        }
        if ($resolved === null) {
            throw new CostPlanDefinitionException('Unable to infer tax context for CostPlan.');
        }
        return $resolved;
    }

    private function presetTaxContext(TariffPreset $preset): TaxContext
    {
        $context = $preset->document['taxContext'] ?? null;
        if (!is_array($context) || array_is_list($context)
            || !is_string($context['jurisdiction'] ?? null) || trim($context['jurisdiction']) === ''
            || !is_string($context['customerClass'] ?? null) || trim($context['customerClass']) === '') {
            throw new CostPlanDefinitionException("Preset '{$preset->id}' has invalid or missing taxContext.");
        }
        foreach (array_keys($context) as $key) {
            if (!in_array($key, ['jurisdiction', 'customerClass'], true)) {
                throw new CostPlanDefinitionException("Preset '{$preset->id}' taxContext has unsupported property '$key'.");
            }
        }
        return new TaxContext($context['jurisdiction'], $context['customerClass']);
    }

    /**
     * @param list<array{validFrom: ?\DateTimeImmutable, validTo: ?\DateTimeImmutable, profileId: string, profile: \Supla\EnergyCostCalculator\Tax\TaxProfile}> $assignments
     * @return list<array<string, mixed>>
     */
    private function compileTaxRuleSets(array $assignments): array
    {
        $sets = [];
        foreach ($assignments as $entry) {
            $profile = $entry['profile'];
            $sets[] = [
                'validFrom' => $entry['validFrom']?->format(DATE_ATOM),
                'validTo' => $entry['validTo']?->format(DATE_ATOM),
                'rules' => array_map(static fn($rule) => array_filter([
                    'id' => $rule->id,
                    'type' => $rule->type,
                    'appliesToKinds' => array_map(
                        static fn(CostComponentKind $componentKind): string => $componentKind->value,
                        $rule->appliesToKinds,
                    ),
                    'rate' => $rule->rate, 'unit' => $rule->unit, 'base' => $rule->base,
                ], static fn($value) => $value !== null), $profile->rules),
            ];
        }
        return $sets;
    }

    /** @param list<array<string, mixed>> $segments */
    private function assertCoverage(array $segments, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, string $context): void
    {
        usort($segments, fn(array $a, array $b) => $this->boundaryTimestamp($a['validFrom'], PHP_INT_MIN) <=> $this->boundaryTimestamp($b['validFrom'], PHP_INT_MIN));
        $cursor = $from;
        foreach ($segments as $segment) {
            if (!$this->sameBoundary($this->documentDate($segment['validFrom'], 'compiled validFrom'), $cursor)) {
                throw new CostPlanDefinitionException("Preset does not cover $context continuously.");
            }
            $cursor = $this->documentDate($segment['validTo'], 'compiled validTo');
        }
        if (!$this->sameBoundary($cursor, $to)) {
            throw new CostPlanDefinitionException("Preset does not cover $context continuously.");
        }
    }

    private function sameBoundary(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left == $right;
    }

    /** @param list<CostPlanPeriod> $periods */
    private function assertContinuousPlanPeriods(array $periods): void
    {
        $count = count($periods);
        foreach ($periods as $index => $period) {
            if (!$period instanceof CostPlanPeriod
                || ($period->validFrom !== null && $period->validTo !== null && $period->validFrom >= $period->validTo)
                || $period->components === []) {
                throw new CostPlanDefinitionException("Invalid cost plan period $index.");
            }
            if ($count === 1) {
                continue;
            }
            if ($index === 0 && $period->validTo === null) {
                throw new CostPlanDefinitionException('periods[0].validTo is required when multiple periods are defined.');
            }
            if ($index === $count - 1 && $period->validFrom === null) {
                throw new CostPlanDefinitionException("periods[$index].validFrom is required when multiple periods are defined.");
            }
            if ($index > 0 && $index < $count - 1 && ($period->validFrom === null || $period->validTo === null)) {
                throw new CostPlanDefinitionException("periods[$index] must define validFrom and validTo.");
            }
            if ($index > 0 && !$this->sameBoundary($periods[$index - 1]->validTo, $period->validFrom)) {
                throw new CostPlanDefinitionException('Cost plan periods must be contiguous and ordered.');
            }
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
