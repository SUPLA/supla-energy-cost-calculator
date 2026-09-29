<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tax;

use Supla\EnergyCostCalculator\Exception\DefinitionException;

final class TaxProfileCatalog
{
    /** @var array<string, TaxProfile> */
    private array $profiles = [];

    public function __construct(private readonly ?string $directory = null, private readonly TaxProfileParser $parser = new TaxProfileParser())
    {
    }

    public function get(string $id): TaxProfile
    {
        if (isset($this->profiles[$id])) {
            return $this->profiles[$id];
        }
        $index = $this->decode($this->path('index.json'));
        foreach ($index['profiles'] ?? [] as $entry) {
            if (($entry['id'] ?? null) !== $id) {
                continue;
            }
            $path = $entry['path'] ?? null;
            if (!is_string($path) || $path === '') {
                break;
            }
            $profile = $this->parser->parse($this->decode($this->path($path)));
            if ($profile->id !== $id) {
                throw new DefinitionException("Tax profile '$id' document id does not match its catalogue id.");
            }
            return $this->profiles[$id] = $profile;
        }
        throw new DefinitionException("Unknown tax profile '$id'.");
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new DefinitionException("Cannot read tax profile resource '$path'.");
        }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function path(string $relative): string
    {
        $root = rtrim($this->directory ?? dirname(__DIR__, 2) . '/resources/tax-profiles', DIRECTORY_SEPARATOR);
        $path = realpath($root . DIRECTORY_SEPARATOR . $relative);
        if ($path === false || !str_starts_with($path, realpath($root) . DIRECTORY_SEPARATOR)) {
            throw new DefinitionException("Tax profile resource '$relative' does not exist.");
        }
        return $path;
    }
}
