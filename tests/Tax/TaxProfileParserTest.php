<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Tax;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Tax\TaxProfileParser;
use Supla\EnergyCostCalculator\Tax\TaxProfileCatalog;
use Supla\EnergyCostCalculator\Model\CostComponentKind;

final class TaxProfileParserTest extends TestCase
{
    public function testRejectsUnknownComponentKind(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('unknown component kind');

        (new TaxProfileParser())->parse($this->profile([
            'id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['TYPO'],
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

    public function testStandardHouseholdProfileAppliesExciseAndVatToExpectedKinds(): void
    {
        $profile = (new TaxProfileCatalog())->get('PL.HOUSEHOLD.VAT23_EXCISE5.V1');
        $rules = array_column($profile->rules, null, 'id');

        self::assertSame('0.005', $rules['EXCISE']->rate);
        self::assertSame('0.23', $rules['VAT']->rate);
        self::assertSame(CostComponentKind::ENERGY_PURCHASE, $rules['EXCISE']->appliesToKinds[0]);
        self::assertSame([
            CostComponentKind::ENERGY_PURCHASE,
            CostComponentKind::DISTRIBUTION_VARIABLE,
            CostComponentKind::DISTRIBUTION_FIXED,
            CostComponentKind::SUPPLIER_FIXED,
        ], $rules['VAT']->appliesToKinds);
    }

    public function testAntiInflationShieldProfileKeepsTaxOrderWithZeroExciseAndFivePercentVat(): void
    {
        $profile = (new TaxProfileCatalog())->get('PL.HOUSEHOLD.VAT5_EXCISE0.V1');
        $rules = array_column($profile->rules, null, 'id');

        self::assertSame(['EXCISE', 'VAT'], array_column($profile->rules, 'id'));
        self::assertSame('0', $rules['EXCISE']->rate);
        self::assertSame('0.05', $rules['VAT']->rate);
        self::assertSame(CostComponentKind::ENERGY_PURCHASE, $rules['EXCISE']->appliesToKinds[0]);
    }

    /** @return array<string, mixed> */
    private function profile(array ...$rules): array
    {
        return ['version' => 1, 'id' => 'TEST', 'label' => 'Test', 'currency' => 'PLN', 'rules' => $rules];
    }
}
