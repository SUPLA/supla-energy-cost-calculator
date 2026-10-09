<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Preset;

use Supla\EnergyCostCalculator\Exception\TariffPresetCompilationException;

/** Expands independently dated component rates to the executable, shared period timeline. */
final class ComponentPricePeriodExpander
{
    /** @param array<string, mixed> $template @return array<string, mixed> */
    public function expand(array $template, string $presetId): array
    {
        if (!array_key_exists('components', $template)) {
            throw new TariffPresetCompilationException("Preset '$presetId' must use billingDefinitionTemplate.components with pricePeriods.");
        }
        if (array_key_exists('periods', $template)) {
            throw new TariffPresetCompilationException("Preset '$presetId' cannot mix components and periods templates.");
        }
        $components = $template['components'];
        if (!is_array($components) || !array_is_list($components) || $components === []) {
            throw new TariffPresetCompilationException("Preset '$presetId' requires non-empty components array.");
        }

        $boundaries = [];
        $first = [];
        $last = [];
        $seenIds = [];
        foreach ($components as $index => $component) {
            if (!is_array($component) || array_is_list($component)) {
                throw new TariffPresetCompilationException("Preset '$presetId' has invalid component $index.");
            }
            $id = $component['id'] ?? null;
            if (!is_string($id) || $id === '' || isset($seenIds[$id])) {
                throw new TariffPresetCompilationException("Preset '$presetId' has missing or duplicate component id at $index.");
            }
            $seenIds[$id] = true;
            if (array_key_exists('rate', $component)) {
                throw new TariffPresetCompilationException("Preset '$presetId' component '$id' must declare rate inside pricePeriods only.");
            }
            $periods = $component['pricePeriods'] ?? null;
            if (!is_array($periods) || !array_is_list($periods) || $periods === []) {
                throw new TariffPresetCompilationException("Preset '$presetId' component '$id' must contain pricePeriods.");
            }
            $previousTo = null;
            foreach ($periods as $periodIndex => $period) {
                $from = $this->parseBoundary($period['validFrom'] ?? null, $presetId, $id);
                $to = $this->parseBoundary($period['validTo'] ?? null, $presetId, $id);
                if (!is_array($period) || !isset($period['rate']) || !is_array($period['rate']) || array_is_list($period['rate'])) {
                    throw new TariffPresetCompilationException("Preset '$presetId' component '$id' has invalid rate at price period $periodIndex.");
                }
                if ($from !== null && $to !== null && $from >= $to) {
                    throw new TariffPresetCompilationException("Preset '$presetId' component '$id' has inverted price period $periodIndex.");
                }
                if ($periodIndex > 0 && ($from === null || $previousTo === null || $from != $previousTo)) {
                    throw new TariffPresetCompilationException("Preset '$presetId' component '$id' has non-contiguous price periods.");
                }
                if ($periodIndex > 0 && $from !== null) {
                    $boundaries[$from->format('U.u')] = [$from, $period['validFrom']];
                }
                $previousTo = $to;
            }
            $first[$id] = $this->parseBoundary($periods[0]['validFrom'] ?? null, $presetId, $id);
            $last[$id] = $previousTo;
        }

        // Component histories must cover the same executable extent; normal tariffs use null/null.
        $firstStart = reset($first);
        $lastEnd = reset($last);
        foreach ($components as $component) {
            $id = $component['id'];
            if (!$this->sameDate($first[$id], $firstStart) || !$this->sameDate($last[$id], $lastEnd)) {
                throw new TariffPresetCompilationException("Preset '$presetId' component '$id' does not cover the template's time range.");
            }
        }
        if ($firstStart !== null) {
            $boundaries[$firstStart->format('U.u')] = [$firstStart, $components[0]['pricePeriods'][0]['validFrom']];
        }
        if ($lastEnd !== null) {
            $boundaries[$lastEnd->format('U.u')] = [$lastEnd, $components[0]['pricePeriods'][array_key_last($components[0]['pricePeriods'])]['validTo']];
        }
        uasort($boundaries, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $dates = array_column(array_values($boundaries), 1);
        $starts = $firstStart === null ? [null, ...$dates] : $dates;
        if ($lastEnd !== null) {
            array_pop($starts); // The final boundary is exclusive.
        }
        $ends = array_slice($starts, 1);
        $ends[] = $lastEnd === null ? null : $components[0]['pricePeriods'][array_key_last($components[0]['pricePeriods'])]['validTo'];
        $outputPeriods = [];
        foreach ($starts as $i => $start) {
            $timestamp = $start === null ? null : new \DateTimeImmutable($start);
            $active = [];
            foreach ($components as $component) {
                $source = $component['pricePeriods'];
                $rate = null;
                foreach ($source as $periodIndex => $period) {
                    $validFrom = $this->parseBoundary($period['validFrom'] ?? null, $presetId, $component['id']);
                    $validTo = $this->parseBoundary($period['validTo'] ?? null, $presetId, $component['id']);
                    if (($timestamp === null && $periodIndex === 0)
                        || ($timestamp !== null && ($validFrom === null || $validFrom <= $timestamp) && ($validTo === null || $timestamp < $validTo))) {
                        $rate = $period['rate'];
                        break;
                    }
                }
                if ($rate === null) {
                    throw new TariffPresetCompilationException("Preset '$presetId' has a rate coverage gap in component '{$component['id']}'.");
                }
                $definition = $component;
                unset($definition['pricePeriods']);
                $definition['rate'] = $rate;
                $active[] = $definition;
            }
            $outputPeriods[] = ['validFrom' => $start, 'validTo' => $ends[$i] ?? null, 'components' => $active];
        }
        unset($template['components']);
        $template['periods'] = $outputPeriods;
        return $template;
    }

    private function parseBoundary(mixed $value, string $presetId, string $componentId): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new TariffPresetCompilationException("Preset '$presetId' component '$componentId' has non-string date boundary.");
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $error) {
            throw new TariffPresetCompilationException("Preset '$presetId' component '$componentId' has invalid date boundary.", previous: $error);
        }
    }

    private function sameDate(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left == $right;
    }
}
