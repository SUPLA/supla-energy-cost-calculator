<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Preset;

final readonly class TariffPreset
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $document
     */
    public function __construct(
        public string $id,
        public string $revision,
        public array $metadata,
        public array $document,
    ) {
    }
}
