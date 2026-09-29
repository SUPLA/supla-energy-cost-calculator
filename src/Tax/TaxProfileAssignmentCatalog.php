<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tax;

use Supla\EnergyCostCalculator\Exception\DefinitionException;

final class TaxProfileAssignmentCatalog
{
    /** @var list<TaxProfileAssignment>|null */
    private ?array $assignments = null;

    public function __construct(private readonly ?string $directory = null)
    {
    }

    /** @return list<TaxProfileAssignment> */
    public function assignments(): array
    {
        if ($this->assignments !== null) {
            return $this->assignments;
        }

        $data = $this->decode($this->path('assignments.json'));
        if (!is_array($data['assignments'] ?? null) || !array_is_list($data['assignments'])) {
            throw new DefinitionException('Tax profile assignments must contain an assignments array.');
        }

        $result = [];
        foreach ($data['assignments'] as $index => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new DefinitionException("Tax profile assignments[$index] must be an object.");
            }
            foreach (array_keys($entry) as $key) {
                if (!in_array($key, ['taxContext', 'validFrom', 'validTo', 'profileId'], true)) {
                    throw new DefinitionException("Tax profile assignments[$index] has unsupported property '$key'.");
                }
            }
            $context = $this->parseContext($entry['taxContext'] ?? null, "assignments[$index].taxContext");
            $from = $this->parseDate($entry['validFrom'] ?? null, "assignments[$index].validFrom");
            $to = $this->parseDate($entry['validTo'] ?? null, "assignments[$index].validTo");
            $profileId = $entry['profileId'] ?? null;
            if ($from !== null && $to !== null && $from >= $to) {
                throw new DefinitionException("Tax profile assignments[$index] has an invalid validity range.");
            }
            if (!is_string($profileId) || trim($profileId) === '') {
                throw new DefinitionException("Tax profile assignments[$index].profileId must be a non-empty string.");
            }
            $result[] = new TaxProfileAssignment($context, $from, $to, $profileId);
        }

        return $this->assignments = $result;
    }

    private function parseContext(mixed $value, string $path): TaxContext
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new DefinitionException("$path must be an object.");
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, ['jurisdiction', 'customerClass'], true)) {
                throw new DefinitionException("$path has unsupported property '$key'.");
            }
        }
        $jurisdiction = $value['jurisdiction'] ?? null;
        $customerClass = $value['customerClass'] ?? null;
        if (!is_string($jurisdiction) || trim($jurisdiction) === '' || !is_string($customerClass) || trim($customerClass) === '') {
            throw new DefinitionException("$path requires jurisdiction and customerClass.");
        }
        return new TaxContext($jurisdiction, $customerClass);
    }

    private function parseDate(mixed $value, string $path): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new DefinitionException("$path must contain an explicit UTC offset or Z suffix.");
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new DefinitionException("Invalid date at $path.", previous: $e);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new DefinitionException("Cannot read tax profile assignment resource '$path'.");
        }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function path(string $relative): string
    {
        $root = realpath($this->directory ?? dirname(__DIR__, 2) . '/resources/tax-profiles');
        if ($root === false) {
            throw new DefinitionException('Tax profile resource directory does not exist.');
        }
        $path = realpath($root . DIRECTORY_SEPARATOR . $relative);
        if ($path === false || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new DefinitionException("Tax profile assignment resource '$relative' does not exist.");
        }
        return $path;
    }
}
