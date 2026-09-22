<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Preset;

use Supla\EnergyCostCalculator\Definition\BillingDefinition;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Exception\TariffPresetCompilationException;

final class TariffPresetCompiler
{
    public function __construct(
        private readonly TariffPresetCatalog $catalog = new TariffPresetCatalog(),
        private readonly BillingDefinitionParser $definitionParser = new BillingDefinitionParser(),
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function compile(string|TariffPreset $preset, array $values): BillingDefinition
    {
        return $this->definitionParser->parse($this->compileToArray($preset, $values));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function compileToArray(string|TariffPreset $preset, array $values): array
    {
        $preset = is_string($preset) ? $this->catalog->get($preset) : $preset;
        $document = $preset->document;
        $template = $document['billingDefinitionTemplate'] ?? null;
        if (!is_array($template) || array_is_list($template)) {
            throw new TariffPresetCompilationException("Tariff preset '{$preset->id}' must contain billingDefinitionTemplate object.");
        }

        $inputs = $document['inputs'] ?? null;
        if (!is_array($inputs) || !array_is_list($inputs)) {
            throw new TariffPresetCompilationException("Tariff preset '{$preset->id}' must contain inputs array.");
        }

        $definitions = [];
        foreach ($inputs as $index => $input) {
            if (!is_array($input) || array_is_list($input)) {
                throw new TariffPresetCompilationException("Tariff preset '{$preset->id}' inputs[$index] must be an object.");
            }
            $id = $input['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                throw new TariffPresetCompilationException("Tariff preset '{$preset->id}' inputs[$index].id must be a non-empty string.");
            }
            if (isset($definitions[$id])) {
                throw new TariffPresetCompilationException("Tariff preset '{$preset->id}' contains duplicate input id '$id'.");
            }
            $definitions[$id] = $input;
        }

        foreach (array_keys($values) as $id) {
            if (!is_string($id) || !isset($definitions[$id])) {
                $display = is_scalar($id) ? (string)$id : gettype($id);
                throw new TariffPresetCompilationException("Unknown input '$display' for tariff preset '{$preset->id}'.");
            }
        }

        $compiled = $template;
        foreach ($definitions as $id => $input) {
            $targets = $input['targets'] ?? null;
            if (!is_array($targets) || !array_is_list($targets) || $targets === []) {
                throw new TariffPresetCompilationException("Tariff preset '{$preset->id}' input '$id' must contain non-empty targets array.");
            }

            if (array_key_exists($id, $values)) {
                $value = $this->normalizeValue($values[$id], $input, $preset->id, $id);
                foreach ($targets as $target) {
                    if (!is_string($target)) {
                        throw new TariffPresetCompilationException("Tariff preset '{$preset->id}' input '$id' contains a non-string target.");
                    }
                    $this->replacePointer($compiled, $target, $value, $preset->id, $id);
                }
            }

            if (($input['required'] ?? false) === true) {
                foreach ($targets as $target) {
                    if (!is_string($target)) {
                        continue;
                    }
                    $resolved = $this->readPointer($compiled, $target, $preset->id, $id);
                    if ($resolved === null || $resolved === '') {
                        throw new TariffPresetCompilationException("Required input '$id' for tariff preset '{$preset->id}' is unresolved.");
                    }
                    // Validate preset defaults too, not only submitted values.
                    $this->normalizeValue($resolved, $input, $preset->id, $id);
                }
            }
        }

        // The parser is the final semantic authority for the executable definition.
        $this->definitionParser->parse($compiled);

        return $compiled;
    }

    /** @param array<string, mixed> $input */
    private function normalizeValue(mixed $value, array $input, string $presetId, string $inputId): mixed
    {
        $type = $input['type'] ?? null;
        if (!is_string($type)) {
            throw new TariffPresetCompilationException("Tariff preset '$presetId' input '$inputId' has invalid type.");
        }

        return match ($type) {
            'DECIMAL' => $this->normalizeDecimal($value, $presetId, $inputId),
            'INTEGER' => $this->normalizeInteger($value, $input, $presetId, $inputId),
            'DATETIME' => $this->normalizeDateTime($value, $presetId, $inputId),
            'TIME' => $this->normalizeTime($value, $presetId, $inputId),
            default => throw new TariffPresetCompilationException("Tariff preset '$presetId' input '$inputId' uses unsupported type '$type'."),
        };
    }

    private function normalizeDecimal(mixed $value, string $presetId, string $inputId): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new TariffPresetCompilationException("Input '$inputId' for tariff preset '$presetId' must be decimal.");
        }
        if (is_float($value) && !is_finite($value)) {
            throw new TariffPresetCompilationException("Input '$inputId' for tariff preset '$presetId' must be finite decimal.");
        }
        $normalized = (string)$value;
        if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $normalized)) {
            throw new TariffPresetCompilationException("Input '$inputId' for tariff preset '$presetId' must be decimal.");
        }
        return $normalized;
    }

    /** @param array<string, mixed> $input */
    private function normalizeInteger(mixed $value, array $input, string $presetId, string $inputId): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^-?(?:0|[1-9]\d*)$/', $value)) {
            $normalized = (int)$value;
        } else {
            throw new TariffPresetCompilationException("Input '$inputId' for tariff preset '$presetId' must be integer.");
        }

        if (isset($input['minimum']) && is_int($input['minimum']) && $normalized < $input['minimum']) {
            throw new TariffPresetCompilationException("Input '$inputId' for tariff preset '$presetId' must be at least {$input['minimum']}.");
        }
        return $normalized;
    }

    private function normalizeDateTime(mixed $value, string $presetId, string $inputId): string
    {
        if (!is_string($value) || !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new TariffPresetCompilationException("Input '$inputId' for tariff preset '$presetId' must be ISO-8601 date-time with explicit offset or Z.");
        }
        try {
            new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new TariffPresetCompilationException("Input '$inputId' for tariff preset '$presetId' contains invalid date-time.", previous: $e);
        }
        return $value;
    }

    private function normalizeTime(mixed $value, string $presetId, string $inputId): string
    {
        if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$|^24:00$/', $value)) {
            throw new TariffPresetCompilationException("Input '$inputId' for tariff preset '$presetId' must be HH:MM or 24:00.");
        }
        return $value;
    }

    /**
     * @param array<string|int, mixed> $document
     */
    private function replacePointer(array &$document, string $pointer, mixed $value, string $presetId, string $inputId): void
    {
        $tokens = $this->pointerTokens($pointer, $presetId, $inputId);
        $cursor =& $document;
        foreach ($tokens as $position => $token) {
            $last = $position === array_key_last($tokens);
            $key = $this->resolveArrayKey($cursor, $token, $pointer, $presetId, $inputId);
            if (!array_key_exists($key, $cursor)) {
                throw new TariffPresetCompilationException("Target '$pointer' for input '$inputId' in tariff preset '$presetId' does not exist.");
            }
            if ($last) {
                $cursor[$key] = $value;
                return;
            }
            if (!is_array($cursor[$key])) {
                throw new TariffPresetCompilationException("Target '$pointer' for input '$inputId' in tariff preset '$presetId' crosses a scalar value.");
            }
            $cursor =& $cursor[$key];
        }
    }

    /**
     * @param array<string|int, mixed> $document
     */
    private function readPointer(array $document, string $pointer, string $presetId, string $inputId): mixed
    {
        $tokens = $this->pointerTokens($pointer, $presetId, $inputId);
        $cursor = $document;
        foreach ($tokens as $position => $token) {
            $key = $this->resolveArrayKey($cursor, $token, $pointer, $presetId, $inputId);
            if (!array_key_exists($key, $cursor)) {
                throw new TariffPresetCompilationException("Target '$pointer' for input '$inputId' in tariff preset '$presetId' does not exist.");
            }
            $value = $cursor[$key];
            if ($position === array_key_last($tokens)) {
                return $value;
            }
            if (!is_array($value)) {
                throw new TariffPresetCompilationException("Target '$pointer' for input '$inputId' in tariff preset '$presetId' crosses a scalar value.");
            }
            $cursor = $value;
        }
        return $cursor;
    }

    /** @return list<string> */
    private function pointerTokens(string $pointer, string $presetId, string $inputId): array
    {
        if ($pointer === '' || $pointer[0] !== '/') {
            throw new TariffPresetCompilationException("Target '$pointer' for input '$inputId' in tariff preset '$presetId' is not a JSON Pointer.");
        }
        $tokens = explode('/', substr($pointer, 1));
        foreach ($tokens as &$token) {
            if (preg_match('/~(?![01])/', $token)) {
                throw new TariffPresetCompilationException("Target '$pointer' for input '$inputId' in tariff preset '$presetId' contains invalid JSON Pointer escape.");
            }
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
        }
        unset($token);
        return $tokens;
    }

    /**
     * @param array<string|int, mixed> $cursor
     * @return string|int
     */
    private function resolveArrayKey(array $cursor, string $token, string $pointer, string $presetId, string $inputId): string|int
    {
        if (array_is_list($cursor)) {
            if (!preg_match('/^(?:0|[1-9]\d*)$/', $token)) {
                throw new TariffPresetCompilationException("Target '$pointer' for input '$inputId' in tariff preset '$presetId' must use numeric array indexes.");
            }
            return (int)$token;
        }
        return $token;
    }
}
