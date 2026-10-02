<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Definition\SelectorDefinition;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\TimeRange;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;
use Supla\EnergyCostCalculator\Reference\ReferenceDataCache;
use Supla\EnergyCostCalculator\Strategy\Selector\DefaultSelectorResolver;
use Supla\EnergyCostCalculator\Tests\Support\InMemoryReferenceDataSource;

final class TariffPresetWeeklyScheduleCoverageTest extends TestCase
{
    public function testWeeklySchedulesCoverEveryQuarterHourOfNormalWeeks(): void
    {
        $resolver = new DefaultSelectorResolver();
        $catalog = new TariffPresetCatalog();
        $ranges = [
            new TimeRange(
                new \DateTimeImmutable('2026-01-12T00:00:00+01:00'),
                new \DateTimeImmutable('2026-01-19T00:00:00+01:00'),
            ),
            new TimeRange(
                new \DateTimeImmutable('2026-06-08T00:00:00+02:00'),
                new \DateTimeImmutable('2026-06-15T00:00:00+02:00'),
            ),
        ];

        foreach ($catalog->presets() as $metadata) {
            $preset = $catalog->get($metadata['id']);
            foreach ($preset->document['billingDefinitionTemplate']['periods'] as $period) {
                foreach ($period['components'] as $component) {
                    $selector = $component['selector'] ?? null;
                    if (!is_array($selector) || ($selector['type'] ?? null) !== 'WEEKLY_SCHEDULE') {
                        continue;
                    }

                    $definition = new SelectorDefinition('WEEKLY_SCHEDULE', $selector);
                    foreach ($ranges as $range) {
                        $references = new ReferenceDataCache(new InMemoryReferenceDataSource(), $range);
                        for ($from = $range->from; $from < $range->to; $from = $from->modify('+15 minutes')) {
                            try {
                                $selection = $resolver->resolve(new EnergyDelta($from, $from->modify('+15 minutes'), []), $definition, $references);
                            } catch (\Throwable $exception) {
                                self::fail(sprintf('%s %s leaves %s without a zone: %s', $preset->id, $component['id'], $from->format(DATE_ATOM), $exception->getMessage()));
                            }
                            self::assertNotSame('', $selection, sprintf('%s %s leaves %s without a zone.', $preset->id, $component['id'], $from->format(DATE_ATOM)));
                        }
                    }
                }
            }
        }
    }
}
