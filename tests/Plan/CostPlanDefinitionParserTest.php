<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Plan\CostPlanDefinitionParser;

final class CostPlanDefinitionParserTest extends TestCase
{
    public function testParsesEntryWithOptionalEffectiveRange(): void
    {
        $plan = (new CostPlanDefinitionParser())->parse([
            'version' => 1,
            'entries' => [[
                'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
                'values' => ['energy.rate' => '0.70'],
            ]],
        ]);

        self::assertNull($plan->entries[0]->validFrom);
        self::assertNull($plan->entries[0]->validTo);
        self::assertSame('PL.TAURON_DYSTRYBUCJA.G11.2026', $plan->entries[0]->presetId);
    }

    public function testRejectsInvalidExplicitRange(): void
    {
        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('validFrom must be before validTo');

        (new CostPlanDefinitionParser())->parse([
            'version' => 1,
            'entries' => [$this->entry('2026-07-01T00:00:00+02:00', '2026-01-01T00:00:00+01:00')],
        ]);
    }

    public function testRequiresExplicitTimezoneOffsetWhenBoundaryIsProvided(): void
    {
        $this->expectException(CostPlanDefinitionException::class);
        $this->expectExceptionMessage('explicit UTC offset');

        (new CostPlanDefinitionParser())->parse([
            'version' => 1,
            'entries' => [$this->entry('2026-01-01T00:00:00', '2026-07-01T00:00:00+02:00')],
        ]);
    }

    /** @return array<string, mixed> */
    private function entry(?string $from, ?string $to): array
    {
        return [
            'validFrom' => $from,
            'validTo' => $to,
            'presetId' => 'PL.TAURON_DYSTRYBUCJA.G11.2026',
            'values' => ['energy.rate' => '0.70'],
        ];
    }
}
