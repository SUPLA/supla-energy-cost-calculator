<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Preset;

/**
 * Normalizes legacy executable preset timelines into independently dated component rates.
 * Only consolidates adjacent periods with byte-equivalent rate definitions and identical
 * non-price component definitions. Unsupported inputs fail explicitly for manual review.
 */
final class TariffPresetPricePeriodMigrator
{
    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function convert(array $document): array
    {
        $template = $document['billingDefinitionTemplate'] ?? null;
        if (!is_array($template) || array_is_list($template)) {
            throw new \RuntimeException('Preset has no billingDefinitionTemplate object.');
        }
        if (array_key_exists('components', $template)) {
            if (array_key_exists('periods', $template)) {
                throw new \RuntimeException('Preset mixes components and legacy periods.');
            }
            return $document; // Already migrated.
        }
        if (!array_key_exists('periods', $template)) {
            throw new \RuntimeException('Preset has neither components nor periods.');
        }
        $periods = $template['periods'];
        if (!is_array($periods) || !array_is_list($periods) || $periods === []
            || !is_array($periods[0]['components'] ?? null)) {
            throw new \RuntimeException('Legacy preset has malformed or empty periods array.');
        }
        $sourceComponents = $periods[0]['components'];
        if (!array_is_list($sourceComponents) || $sourceComponents === []) {
            throw new \RuntimeException('First legacy period has malformed or empty components array.');
        }

        $components = [];
        $periodMap = [];
        foreach ($sourceComponents as $componentIndex => $component) {
            if (!is_array($component) || !isset($component['rate'])) {
                throw new \RuntimeException("Legacy component $componentIndex has no rate.");
            }
            $base = $component;
            unset($base['rate']);
            $rates = [];
            $map = [];
            foreach ($periods as $periodIndex => $period) {
                $current = $period['components'][$componentIndex] ?? null;
                if (!is_array($current) || !is_array($current['rate'] ?? null)) {
                    throw new \RuntimeException("Period $periodIndex has no rate for component $componentIndex.");
                }
                $struct = $current;
                unset($struct['rate']);
                if ($struct !== $base || count($period['components']) !== count($sourceComponents)) {
                    throw new \RuntimeException("Period $periodIndex changes non-price fields or component count for component $componentIndex ('{$base['id']}').");
                }
                $last = count($rates) - 1;
                if ($last >= 0 && $rates[$last]['rate'] === $current['rate']
                    && $rates[$last]['validTo'] === ($period['validFrom'] ?? null)) {
                    $rates[$last]['validTo'] = $period['validTo'] ?? null;
                    $map[$periodIndex] = $last;
                } else {
                    $map[$periodIndex] = count($rates);
                    $rates[] = [
                        'validFrom' => $period['validFrom'] ?? null,
                        'validTo' => $period['validTo'] ?? null,
                        'rate' => $current['rate'],
                    ];
                }
            }
            $base['pricePeriods'] = $rates;
            $components[] = $base;
            $periodMap[$componentIndex] = $map;
        }

        // JSON pointers to price periods can collapse several old inputs onto one value.
        $inputs = [];
        $targetOwner = [];
        foreach ($document['inputs'] ?? [] as $input) {
            $rewritten = [];
            foreach ($input['targets'] as $target) {
                $pointer = is_string($target) ? $target : $target['pointer'];
                if (preg_match('~^/periods/(\d+)/components/(\d+)/(.*)$~', $pointer, $match)) {
                    $periodIndex = (int)$match[1];
                    $componentIndex = (int)$match[2];
                    $suffix = $match[3];
                    if (!isset($periodMap[$componentIndex][$periodIndex])) {
                        throw new \RuntimeException("Invalid tariff preset input pointer '$pointer'.");
                    }
                    $pointer = str_starts_with($suffix, 'rate/')
                        ? '/components/' . $componentIndex . '/pricePeriods/' . $periodMap[$componentIndex][$periodIndex] . '/' . $suffix
                        : '/components/' . $componentIndex . '/' . $suffix;
                }
                $newTarget = is_string($target) ? $pointer : [...$target, 'pointer' => $pointer];
                $rewritten[json_encode($newTarget, JSON_THROW_ON_ERROR)] = $newTarget;
            }
            $input['targets'] = array_values($rewritten);
            $owners = [];
            foreach ($input['targets'] as $target) {
                $key = is_string($target) ? $target : $target['pointer'];
                if (isset($targetOwner[$key])) {
                    $owners[$targetOwner[$key]] = true;
                }
            }
            if ($owners === []) {
                $inputIndex = count($inputs);
                $inputs[] = $input;
            } elseif (count($owners) === 1) {
                $inputIndex = array_key_first($owners);
                $old = $inputs[$inputIndex];
                if ($old['type'] !== $input['type'] || ($old['required'] ?? null) !== ($input['required'] ?? null)) {
                    throw new \RuntimeException('Different input types target the same collapsed price.');
                }
                foreach ($input['targets'] as $target) {
                    if (!in_array($target, $inputs[$inputIndex]['targets'], true)) {
                        $inputs[$inputIndex]['targets'][] = $target;
                    }
                }
            } else {
                throw new \RuntimeException('A tariff input overlaps multiple independent inputs.');
            }
            foreach ($input['targets'] as $target) {
                $key = is_string($target) ? $target : $target['pointer'];
                $targetOwner[$key] = $inputIndex;
            }
        }

        $inputs = $this->collapseUnchangedRateInputs($inputs, $components);
        foreach ($inputs as &$input) {
            $target = $input['targets'][0] ?? null;
            $pointer = is_string($target) ? $target : ($target['pointer'] ?? null);
            if (count($input['targets']) !== 1 || !is_string($pointer)
                || !preg_match('~^/components/(\d+)/pricePeriods/0/rate/~', $pointer, $match)
                || count($components[(int)$match[1]]['pricePeriods']) !== 1) {
                continue;
            }
            $input['id'] = preg_replace('/\.\d{4}(?:[-_].*)?$/', '', $input['id']);
            $input['label'] = preg_replace('/\s*\(20\d{2}[^)]*\)$/u', '', $input['label']);
            if (isset($input['help'])) {
                $input['help'] = preg_replace('/^Okres 20\d{2}[^.]*\.\s*/u', '', $input['help']);
            }
        }
        unset($input);
        if (count(array_unique(array_column($inputs, 'id'))) !== count($inputs)) {
            throw new \RuntimeException('Duplicate tariff input ids after period normalization.');
        }
        $document['inputs'] = $inputs;
        unset($template['periods']);
        $template['components'] = $components;
        $document['billingDefinitionTemplate'] = $template;
        return $document;
    }
    /**
     * Different zones of a ZONED rate may change at different times. An unchanged
     * zone is one editable input, even if a different zone splits pricePeriods.
     * @param list<array<string, mixed>> $inputs
     * @param list<array<string, mixed>> $components
     * @return list<array<string, mixed>>
     */
    private function collapseUnchangedRateInputs(array $inputs, array $components): array
    {
        $merged = [];
        $owners = [];
        foreach ($inputs as $input) {
            $target = $input['targets'][0] ?? null;
            $pointer = is_string($target) ? $target : ($target['pointer'] ?? null);
            $groupKey = null;
            if (count($input['targets']) === 1 && $input['type'] === 'DECIMAL' && is_string($pointer)
                && preg_match('~^/components/(\d+)/pricePeriods/(\d+)/rate/(value|rates/[^/]+)$~', $pointer, $match)) {
                $component = (int)$match[1];
                $period = (int)$match[2];
                $field = explode('/', $match[3]);
                $getValue = static function (array $rate) use ($field): mixed {
                    foreach ($field as $part) {
                        $rate = $rate[$part] ?? null;
                        if ($rate === null) {
                            return null;
                        }
                    }
                    return $rate;
                };
                $periods = $components[$component]['pricePeriods'];
                $value = $getValue($periods[$period]['rate']);
                $start = $period;
                while ($start > 0 && $getValue($periods[$start - 1]['rate']) === $value) {
                    $start--;
                }
                $groupKey = $component . ':' . $match[3] . ':' . $start;
            }
            if ($groupKey !== null && isset($owners[$groupKey])) {
                $index = $owners[$groupKey];
                if (($merged[$index]['unit'] ?? null) === ($input['unit'] ?? null)) {
                    $merged[$index]['targets'][] = $target;
                    continue;
                }
            }
            $index = count($merged);
            $merged[] = $input;
            if ($groupKey !== null) {
                $owners[$groupKey] = $index;
            }
        }
        foreach ($merged as &$input) {
            if (count($input['targets']) < 2) {
                continue;
            }
            $target = $input['targets'][0];
            $pointer = is_string($target) ? $target : ($target['pointer'] ?? null);
            if (!is_string($pointer) || !preg_match('~^/components/(\d+)/pricePeriods/\d+/rate/~', $pointer, $match)) {
                continue;
            }
            $indexes = [];
            foreach ($input['targets'] as $part) {
                $path = is_string($part) ? $part : ($part['pointer'] ?? '');
                if (preg_match('~^/components/' . $match[1] . '/pricePeriods/(\d+)/rate/~', $path, $found)) {
                    $indexes[] = (int)$found[1];
                }
            }
            if (count($indexes) !== count($input['targets'])) {
                continue;
            }
            $ratePeriods = $components[(int)$match[1]]['pricePeriods'];
            $from = $ratePeriods[min($indexes)]['validFrom'];
            $to = $ratePeriods[max($indexes)]['validTo'];
            $base = preg_replace('/\s*\(20\d{2}[^)]*\)$/u', '', $input['label']);
            if ($from === null && $to === null) {
                $input['label'] = $base;
            } else {
                $range = ($from === null ? 'do ' : 'od ' . substr($from, 0, 10) . ' do ')
                    . ($to === null ? 'nadal' : substr($to, 0, 10));
                $input['label'] = $base . ' (' . $range . ')';
            }
            if (isset($input['help'])) {
                $input['help'] = preg_replace('/^Okres 20\d{2}[^.]*\.\s*/u', '', $input['help']);
            }
        }
        unset($input);
        return $merged;
    }

}
