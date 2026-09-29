<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Tax;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Tax\TaxProfileParser;
use Supla\EnergyCostCalculator\Tax\TaxProfileCatalog;

final class TaxProfileParserTest extends TestCase
{
    public function testRejectsUnknownComponentKind(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('unknown component kind');

        (new TaxProfileParser())->parse($this->profile([
            'id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['UNKNOWN'],
            'rate' => '0.23', 'base' => 'CURRENT_SUBTOTAL',
        ]));
    }

    public function testRequiresPerQuantityUnitToMatchProfileCurrencyPerKwh(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('must be PLN/kWh');

        (new TaxProfileParser())->parse($this->profile([
            'id' => 'EXCISE', 'type' => 'PER_QUANTITY', 'appliesToKinds' => ['ENERGY_PURCHASE'],
            'rate' => '0.005', 'unit' => 'EUR/kWh',
        ]));
    }

    public function testRejectsDuplicateRuleIds(): void
    {
        $rule = [
            'id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['ENERGY_PURCHASE'],
            'rate' => '0.23', 'base' => 'CURRENT_SUBTOTAL',
        ];

        $this->expectException(DefinitionException::class);
        (new TaxProfileParser())->parse($this->profile($rule, $rule));
    }

    public function testHousehold2026AppliesVatToEverySupportedChargeKind(): void
    {
        $profile = (new TaxProfileCatalog())->get('PL.HOUSEHOLD.2026');
        $rules = array_column($profile->rules, null, 'id');

        self::assertSame(['ENERGY_PURCHASE'], $rules['EXCISE']->appliesToKinds);
        self::assertSame([
            'ENERGY_PURCHASE',
            'DISTRIBUTION_VARIABLE',
            'DISTRIBUTION_FIXED',
            'SUPPLIER_FIXED',
        ], $rules['VAT']->appliesToKinds);
    }

    /** @return array<string, mixed> */
    private function profile(array ...$rules): array
    {
        return ['version' => 1, 'id' => 'TEST', 'label' => 'Test', 'currency' => 'PLN', 'rules' => $rules];
    }
}
