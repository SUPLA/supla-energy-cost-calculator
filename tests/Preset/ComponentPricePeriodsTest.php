<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\TariffPresetCompilationException;
use Supla\EnergyCostCalculator\Preset\TariffPreset;
use Supla\EnergyCostCalculator\Preset\TariffPresetCompiler;

final class ComponentPricePeriodsTest extends TestCase
{
    public function testIndependentRateTimelinesAndOverrides(): void
    {
        $compiled = (new TariffPresetCompiler())->compileToArray($this->preset(), [
            'capacity.first' => '9.50',
        ]);

        self::assertCount(3, $compiled['periods']);
        self::assertSame([null, '2025-07-01T00:00:00+02:00', '2026-01-01T00:00:00+01:00'],
            array_column($compiled['periods'], 'validFrom'));
        self::assertSame(['2025-07-01T00:00:00+02:00', '2026-01-01T00:00:00+01:00', null],
            array_column($compiled['periods'], 'validTo'));
        self::assertSame(['7.83', '7.83', '7.83'],
            array_map(static fn(array $period): string => $period['components'][0]['rate']['value'], $compiled['periods']));
        self::assertSame(['9.50', '11.44', '17.18'],
            array_map(static fn(array $period): string => $period['components'][1]['rate']['value'], $compiled['periods']));
    }

    public function testComponentCompilationOnlyAcceptsItsOwnInputs(): void
    {
        $compiler = new TariffPresetCompiler();
        $compiled = $compiler->compileComponentToArray($this->preset(), 'distribution-fixed-network', ['network.rate' => '8.00']);
        self::assertCount(1, $compiled['periods']);
        foreach ($compiled['periods'] as $period) {
            self::assertCount(1, $period['components']);
            self::assertSame('8.00', $period['components'][0]['rate']['value']);
        }

        $this->expectException(TariffPresetCompilationException::class);
        $compiler->compileComponentToArray($this->preset(), 'distribution-fixed-network', ['capacity.first' => '0']);
    }

    public function testRejectsGappedComponentPriceTimeline(): void
    {
        $preset = $this->preset();
        $document = $preset->document;
        $document['billingDefinitionTemplate']['components'][1]['pricePeriods'][1]['validFrom'] = '2025-08-01T00:00:00+02:00';
        $this->expectException(TariffPresetCompilationException::class);
        $this->expectExceptionMessage('non-contiguous');
        (new TariffPresetCompiler())->compileToArray(new TariffPreset('TEST', '', [], $document), []);
    }

    public function testLegacySourcePeriodsAreRejected(): void
    {
        $preset = $this->preset();
        $document = $preset->document;
        unset($document['billingDefinitionTemplate']['components']);
        $document['billingDefinitionTemplate']['periods'] = [[
            'validFrom' => null,
            'validTo' => null,
            'components' => [],
        ]];

        $this->expectException(TariffPresetCompilationException::class);
        $this->expectExceptionMessage('legacy periods are not supported');
        (new TariffPresetCompiler())->compileToArray(new TariffPreset('TEST', '', [], $document), []);
    }

    private function preset(): TariffPreset
    {
        $fixed = static fn(string $id, array $periods): array => [
            'id' => $id,
            'kind' => 'DISTRIBUTION_FIXED',
            'category' => 'NETWORK',
            'taxTreatment' => ['included' => []],
            'quantity' => ['type' => 'PERIOD', 'period' => 'MONTH', 'prorate' => false],
            'pricePeriods' => array_map(static fn(array $part): array => [
                'validFrom' => $part[0],
                'validTo' => $part[1],
                'rate' => ['type' => 'CONSTANT', 'value' => $part[2], 'unit' => 'PLN/month'],
            ], $periods),
        ];
        return new TariffPreset('TEST', '', [], [
            'inputs' => [
                ['id' => 'network.rate', 'label' => 'Fixed network', 'type' => 'DECIMAL', 'required' => true,
                    'targets' => ['/components/0/pricePeriods/0/rate/value']],
                ['id' => 'capacity.first', 'label' => 'Capacity 1', 'type' => 'DECIMAL', 'required' => true,
                    'targets' => ['/components/1/pricePeriods/0/rate/value']],
                ['id' => 'capacity.second', 'label' => 'Capacity 2', 'type' => 'DECIMAL', 'required' => true,
                    'targets' => ['/components/1/pricePeriods/1/rate/value']],
                ['id' => 'capacity.third', 'label' => 'Capacity 3', 'type' => 'DECIMAL', 'required' => true,
                    'targets' => ['/components/1/pricePeriods/2/rate/value']],
            ],
            'billingDefinitionTemplate' => [
                'version' => 1,
                'currency' => 'PLN',
                'timezone' => 'Europe/Warsaw',
                'billingCycle' => ['anchor' => '2026-01-01', 'length' => 1, 'unit' => 'MONTH'],
                'components' => [
                    $fixed('distribution-fixed-network', [[null, null, '7.83']]),
                    $fixed('capacity-fee', [
                        [null, '2025-07-01T00:00:00+02:00', '10.64'],
                        ['2025-07-01T00:00:00+02:00', '2026-01-01T00:00:00+01:00', '11.44'],
                        ['2026-01-01T00:00:00+01:00', null, '17.18'],
                    ]),
                ],
            ],
        ]);
    }
}
