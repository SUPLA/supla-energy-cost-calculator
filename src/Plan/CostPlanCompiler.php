<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Plan;

use Supla\EnergyCostCalculator\Definition\BillingDefinition;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Definition\BillingCycleDefinition;
use Supla\EnergyCostCalculator\Definition\BillingCycleUnit;
use Supla\EnergyCostCalculator\Engine\BillingCycleResolver;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Preset\TariffPreset;
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
        if ($plan->version === 2) {
            return $this->compileComponentPlan($plan);
        }
        $this->assertPlanDefinition($plan);

        $version = null;
        $currency = null;
        $timezone = null;
        $periods = [];
        $billingCycles = [];

        foreach ($this->resolveEntries($plan) as $resolved) {
            $index = $resolved['index'];
            $entry = $resolved['entry'];
            $preset = $resolved['preset'];
            $entryFrom = $resolved['validFrom'];
            $entryTo = $resolved['validTo'];
            $fragment = $this->presetCompiler->compileToArray($preset, $entry->values);

            $fragmentVersion = $fragment['version'] ?? 1;
            $fragmentCurrency = $fragment['currency'] ?? null;
            $fragmentTimezone = $fragment['timezone'] ?? 'UTC';
            if (!is_int($fragmentVersion) || !is_string($fragmentCurrency) || !is_string($fragmentTimezone)) {
                throw new CostPlanDefinitionException("Preset '{$entry->presetId}' compiled to invalid top-level billing metadata.");
            }

            $version ??= $fragmentVersion;
            $currency ??= $fragmentCurrency;
            $timezone ??= $fragmentTimezone;
            if ($fragmentVersion !== $version) {
                throw new CostPlanDefinitionException('All cost plan entries must compile to the same BillingDefinition version.');
            }
            if ($fragmentCurrency !== $currency) {
                throw new CostPlanDefinitionException('All cost plan entries must use the same currency.');
            }
            if ($fragmentTimezone !== $timezone) {
                throw new CostPlanDefinitionException('All cost plan entries must use the same timezone.');
            }

            $entryPeriods = $this->clipPeriods($fragment, $entryFrom, $entryTo, $index);
            if ($entryPeriods === []) {
                throw new CostPlanDefinitionException("Cost plan entry $index does not overlap any billing-definition period from preset '{$entry->presetId}'.");
            }
            array_push($periods, ...$entryPeriods);
            array_push($billingCycles, ...$this->clipBillingCycles($fragment, $entryFrom, $entryTo, $entry->presetId));
        }

        usort($periods, fn(array $a, array $b) => $this->boundaryTimestamp($a['validFrom'] ?? null, PHP_INT_MIN) <=> $this->boundaryTimestamp($b['validFrom'] ?? null, PHP_INT_MIN));
        usort($billingCycles, fn(array $a, array $b) => $this->boundaryTimestamp($a['validFrom'] ?? null, PHP_INT_MIN) <=> $this->boundaryTimestamp($b['validFrom'] ?? null, PHP_INT_MIN));
        $billingCycles = $this->mergeAdjacentBillingCycles($billingCycles, $timezone);

        if ($version === null || $currency === null || $timezone === null || $periods === [] || $billingCycles === []) {
            throw new CostPlanDefinitionException('Cost plan did not produce an executable BillingDefinition.');
        }

        $compiled = [
            'version' => $version,
            'currency' => $currency,
            'timezone' => $timezone,
            'billingCycles' => $billingCycles,
            'periods' => $periods,
        ];

        // Final validation catches overlapping/gapped-incompatible source fragments and all calculator semantics.
        $this->definitionParser->parse($compiled);
        return $compiled;
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

    private function assertPlanDefinition(CostPlanDefinition $plan): void
    {
        if ($plan->version !== 1 || $plan->entries === []) {
            throw new CostPlanDefinitionException('Cost plan must use version 1 and contain at least one entry.');
        }

        foreach ($plan->entries as $index => $entry) {
            if (!$entry instanceof CostPlanEntry) {
                throw new CostPlanDefinitionException("Cost plan entry $index must be a CostPlanEntry.");
            }
            if ($entry->validFrom !== null && $entry->validTo !== null && $entry->validFrom >= $entry->validTo) {
                throw new CostPlanDefinitionException("Cost plan entry $index validFrom must be before validTo.");
            }
        }
    }

    /**
     * @return list<array{index:int, entry:CostPlanEntry, preset:TariffPreset, validFrom:?\DateTimeImmutable, validTo:?\DateTimeImmutable}>
     */
    private function resolveEntries(CostPlanDefinition $plan): array
    {
        $resolved = [];
        foreach ($plan->entries as $index => $entry) {
            $preset = $this->catalog->get($entry->presetId);
            $presetFrom = $this->documentDate($preset->document['validFrom'] ?? null, "preset '{$preset->id}'.validFrom");
            $presetTo = $this->documentDate($preset->document['validTo'] ?? null, "preset '{$preset->id}'.validTo");
            $validFrom = $this->maxDate($entry->validFrom, $presetFrom);
            $validTo = $this->minDate($entry->validTo, $presetTo);
            if ($validFrom !== null && $validTo !== null && $validFrom >= $validTo) {
                throw new CostPlanDefinitionException("Cost plan entry $index does not overlap tariff preset '{$preset->id}' validity.");
            }
            $resolved[] = [
                'index' => $index,
                'entry' => $entry,
                'preset' => $preset,
                'validFrom' => $validFrom,
                'validTo' => $validTo,
            ];
        }

        usort($resolved, fn(array $a, array $b) =>
            ($a['validFrom']?->getTimestamp() ?? PHP_INT_MIN) <=> ($b['validFrom']?->getTimestamp() ?? PHP_INT_MIN));

        for ($i = 1, $count = count($resolved); $i < $count; $i++) {
            $previous = $resolved[$i - 1];
            $current = $resolved[$i];
            if ($previous['validTo'] === null || $current['validFrom'] === null || $current['validFrom'] < $previous['validTo']) {
                throw new CostPlanDefinitionException('Effective cost plan entries must not overlap after applying preset validity.');
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $fragment
     * @return list<array<string, mixed>>
     */
    private function clipPeriods(array $fragment, ?\DateTimeImmutable $entryFrom, ?\DateTimeImmutable $entryTo, int $entryIndex): array
    {
        $rawPeriods = $fragment['periods'] ?? null;
        if (!is_array($rawPeriods) || !array_is_list($rawPeriods)) {
            throw new CostPlanDefinitionException("Compiled preset in cost plan entry $entryIndex has invalid periods.");
        }

        $periods = [];
        foreach ($rawPeriods as $periodIndex => $period) {
            if (!is_array($period) || array_is_list($period)) {
                throw new CostPlanDefinitionException("Compiled preset period $periodIndex in cost plan entry $entryIndex must be an object.");
            }
            $from = $this->maxDate($entryFrom, $this->documentDate($period['validFrom'] ?? null, "periods[$periodIndex].validFrom"));
            $to = $this->minDate($entryTo, $this->documentDate($period['validTo'] ?? null, "periods[$periodIndex].validTo"));
            if ($from !== null && $to !== null && $from >= $to) {
                continue;
            }
            $period['validFrom'] = $from?->format(DATE_ATOM);
            $period['validTo'] = $to?->format(DATE_ATOM);
            $periods[] = $period;
        }
        return $periods;
    }

    /**
     * @param array<string, mixed> $fragment
     * @return list<array<string, mixed>>
     */
    private function clipBillingCycles(array $fragment, ?\DateTimeImmutable $entryFrom, ?\DateTimeImmutable $entryTo, string $presetId): array
    {
        if (array_key_exists('billingCycles', $fragment)) {
            $rawCycles = $fragment['billingCycles'];
            if (!is_array($rawCycles) || !array_is_list($rawCycles) || $rawCycles === []) {
                throw new CostPlanDefinitionException("Compiled preset '$presetId' has invalid billingCycles.");
            }
        } else {
            $cycle = $fragment['billingCycle'] ?? [
                'anchor' => null,
                'length' => 1,
                'unit' => 'MONTH',
            ];
            if (!is_array($cycle) || array_is_list($cycle)) {
                throw new CostPlanDefinitionException("Compiled preset '$presetId' has invalid billingCycle.");
            }
            $rawCycles = [[...$cycle, 'validFrom' => null, 'validTo' => null]];
        }

        $cycles = [];
        foreach ($rawCycles as $index => $rawCycle) {
            if (!is_array($rawCycle) || array_is_list($rawCycle)) {
                throw new CostPlanDefinitionException("Compiled preset '$presetId' billingCycles[$index] must be an object.");
            }
            $from = $this->maxDate($entryFrom, $this->documentDate($rawCycle['validFrom'] ?? null, "billingCycles[$index].validFrom"));
            $to = $this->minDate($entryTo, $this->documentDate($rawCycle['validTo'] ?? null, "billingCycles[$index].validTo"));
            if ($from !== null && $to !== null && $from >= $to) {
                continue;
            }

            $cycle = $rawCycle;
            unset($cycle['validFrom'], $cycle['validTo']);
            $cycle = $this->normalizeCycle($cycle, $presetId);
            $cycles[] = [
                'validFrom' => $from?->format(DATE_ATOM),
                'validTo' => $to?->format(DATE_ATOM),
                ...$cycle,
            ];
        }
        return $cycles;
    }

    /**
     * @param list<array<string, mixed>> $cycles
     * @return list<array<string, mixed>>
     */
    private function mergeAdjacentBillingCycles(array $cycles, string $timezone): array
    {
        $merged = [];
        foreach ($cycles as $cycle) {
            if ($merged === []) {
                $merged[] = $cycle;
                continue;
            }

            $lastIndex = array_key_last($merged);
            $previous = $merged[$lastIndex];
            if ($this->sameInstant($previous['validTo'] ?? null, $cycle['validFrom'] ?? null)
                && $this->sameCycle($previous, $cycle, $timezone)) {
                $merged[$lastIndex]['validTo'] = $cycle['validTo'] ?? null;
            } else {
                $merged[] = $cycle;
            }
        }
        return $merged;
    }

    /** @param array<string, mixed> $cycle */
    private function normalizeCycle(array $cycle, string $presetId): array
    {
        $anchor = $cycle['anchor'] ?? null;
        if ($anchor !== null && !is_string($anchor)) {
            throw new CostPlanDefinitionException("Compiled preset '$presetId' billing cycle anchor must be string or null.");
        }
        $length = $cycle['length'] ?? 1;
        if (!is_int($length) || $length < 1) {
            throw new CostPlanDefinitionException("Compiled preset '$presetId' billing cycle length must be a positive integer.");
        }
        $unit = strtoupper((string)($cycle['unit'] ?? 'MONTH'));
        if (!in_array($unit, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true)) {
            throw new CostPlanDefinitionException("Compiled preset '$presetId' billing cycle unit is invalid.");
        }
        return ['anchor' => $anchor, 'length' => $length, 'unit' => $unit];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function sameCycle(array $left, array $right, string $timezone): bool
    {
        $length = $left['length'] ?? 1;
        $unitRaw = $left['unit'] ?? 'MONTH';
        if ($length !== ($right['length'] ?? 1) || $unitRaw !== ($right['unit'] ?? 'MONTH')) {
            return false;
        }

        $leftAnchorRaw = $left['anchor'] ?? null;
        $rightAnchorRaw = $right['anchor'] ?? null;
        if ($leftAnchorRaw === null || $rightAnchorRaw === null) {
            return $leftAnchorRaw === $rightAnchorRaw;
        }
        if (!is_string($leftAnchorRaw) || !is_string($rightAnchorRaw)) {
            return false;
        }

        try {
            $tz = new \DateTimeZone($timezone);
            $leftAnchor = $this->localDate($leftAnchorRaw, $tz);
            $rightAnchor = $this->localDate($rightAnchorRaw, $tz);
            $unit = BillingCycleUnit::from((string)$unitRaw);
            $resolver = new BillingCycleResolver();
            $leftCycle = new BillingCycleDefinition($leftAnchor, (int)$length, $unit);
            $rightCycle = new BillingCycleDefinition($rightAnchor, (int)$length, $unit);

            // Different anchors can still describe the same sequence of boundaries, e.g.
            // Jan 15 and Jul 15 for a monthly 15 -> 15 cycle.
            $rightOnLeft = $resolver->periodContaining($rightAnchor, $leftCycle, $timezone)->from;
            $leftOnRight = $resolver->periodContaining($leftAnchor, $rightCycle, $timezone)->from;
            return $rightOnLeft->getTimestamp() === $rightAnchor->getTimestamp()
                && $leftOnRight->getTimestamp() === $leftAnchor->getTimestamp();
        } catch (\Throwable) {
            return false;
        }
    }

    private function sameInstant(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }
        return $this->boundaryTimestamp($left, PHP_INT_MIN) === $this->boundaryTimestamp($right, PHP_INT_MAX);
    }

    private function localDate(string $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Billing cycle anchor must be an ISO-8601 date.');
        }
        return $date;
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
