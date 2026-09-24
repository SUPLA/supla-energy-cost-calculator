<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Preset;

use Supla\EnergyCostCalculator\Exception\InvalidTariffPresetException;
use Supla\EnergyCostCalculator\Exception\TariffPresetNotFoundException;

final class TariffPresetCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $entries = null;

    /** @var array<string, TariffPreset> */
    private array $loadedPresets = [];

    public function __construct(
        private readonly ?string $presetDirectory = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presets(): array
    {
        $presets = [];
        foreach (array_keys($this->entries()) as $id) {
            $preset = $this->get($id);
            $presets[] = [...$preset->metadata, 'revision' => $preset->revision];
        }

        return $presets;
    }

    public function get(string $id): TariffPreset
    {
        if (isset($this->loadedPresets[$id])) {
            return $this->loadedPresets[$id];
        }

        $entry = $this->entries()[$id] ?? null;
        if ($entry === null) {
            throw new TariffPresetNotFoundException("Unknown tariff preset '$id'.");
        }

        $path = $entry['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            throw new InvalidTariffPresetException("Tariff preset '$id' must declare a non-empty path.");
        }

        $file = $this->resolveResourcePath($path, $id);
        $json = file_get_contents($file);
        if ($json === false) {
            throw new InvalidTariffPresetException("Cannot read tariff preset '$id'.");
        }

        $document = $this->decodeObject($json, "tariff preset '$id'");
        if (($document['id'] ?? null) !== $id) {
            throw new InvalidTariffPresetException("Tariff preset '$id' document id does not match its catalogue id.");
        }

        $metadata = $entry;
        unset($metadata['path']);

        return $this->loadedPresets[$id] = new TariffPreset(
            $id,
            hash('sha256', json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            )),
            $metadata,
            $document,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $indexFiles = [$this->directory() . DIRECTORY_SEPARATOR . 'index.json'];
        $genericIndex = $this->directory() . DIRECTORY_SEPARATOR . 'generic' . DIRECTORY_SEPARATOR . 'index.json';
        if ($this->presetDirectory === null && is_file($genericIndex)) {
            $indexFiles[] = $genericIndex;
        }

        $this->entries = [];
        foreach ($indexFiles as $indexFile) {
            if (!is_file($indexFile)) {
                throw new InvalidTariffPresetException("Tariff preset catalogue '$indexFile' does not exist.");
            }
            $json = file_get_contents($indexFile);
            if ($json === false) {
                throw new InvalidTariffPresetException("Cannot read tariff preset catalogue '$indexFile'.");
            }

            $index = $this->decodeObject($json, 'tariff preset catalogue');
            $presets = $index['presets'] ?? null;
            if (!is_array($presets) || !array_is_list($presets)) {
                throw new InvalidTariffPresetException('Tariff preset catalogue presets must be an array.');
            }

            foreach ($presets as $position => $entry) {
                if (!is_array($entry)) {
                    throw new InvalidTariffPresetException("Tariff preset catalogue presets[$position] must be an object.");
                }
                $id = $entry['id'] ?? null;
                if (!is_string($id) || trim($id) === '') {
                    throw new InvalidTariffPresetException("Tariff preset catalogue presets[$position].id must be a non-empty string.");
                }
                if (isset($this->entries[$id])) {
                    throw new InvalidTariffPresetException("Duplicate tariff preset id '$id'.");
                }
                $this->entries[$id] = $entry;
            }
        }

        return $this->entries;
    }

    private function resolveResourcePath(string $path, string $id): string
    {
        $directory = realpath($this->directory());
        $file = realpath($this->directory() . DIRECTORY_SEPARATOR . $path);
        if ($directory === false || $file === false || !is_file($file)) {
            throw new InvalidTariffPresetException("Tariff preset '$id' resource does not exist.");
        }

        $directoryPrefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($file, $directoryPrefix)) {
            throw new InvalidTariffPresetException("Tariff preset '$id' resource escapes the preset directory.");
        }

        return $file;
    }

    private function directory(): string
    {
        return rtrim(
            $this->presetDirectory ?? dirname(__DIR__, 2) . '/resources/tariff-presets',
            DIRECTORY_SEPARATOR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json, string $context): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidTariffPresetException("Cannot parse $context: {$e->getMessage()}", previous: $e);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidTariffPresetException(ucfirst($context) . ' must contain a JSON object.');
        }

        return $data;
    }
}
