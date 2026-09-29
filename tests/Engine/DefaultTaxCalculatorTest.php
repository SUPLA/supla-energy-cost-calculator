<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Definition\TaxRuleDefinition;
use Supla\EnergyCostCalculator\Definition\TaxTreatment;
use Supla\EnergyCostCalculator\Engine\DefaultTaxCalculator;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Definition\BillingDefinitionParser;
use Supla\EnergyCostCalculator\Exception\DefinitionException;

final class DefaultTaxCalculatorTest extends TestCase
{
    #[DataProvider('includedTaxCases')]
    public function testNormalizesIncludedTaxes(string $source, array $included, string $exclusive): void
    {
        $result = (new DefaultTaxCalculator())->calculate($source, '1', 'ENERGY_PURCHASE', new TaxTreatment($included), $this->rules());

        self::assertSame($exclusive, $result->taxExclusive);
        self::assertSame('0.005', $result->taxes['EXCISE']['amount']);
        self::assertSame('0.505', $result->taxes['VAT']['taxableBase']);
        self::assertSame('0.11615', $result->taxes['VAT']['amount']);
        self::assertSame('0.62115', $result->taxInclusive);
    }

    public static function includedTaxCases(): iterable
    {
        yield ['0.500', [], '0.500'];
        yield ['0.505', ['EXCISE'], '0.5'];
        yield ['0.62115', ['EXCISE', 'VAT'], '0.5'];
    }

    public function testRejectsNonPrefixIncludedTaxes(): void
    {
        $this->expectException(CalculationException::class);
        (new DefaultTaxCalculator())->calculate('0.5', '1', 'ENERGY_PURCHASE', new TaxTreatment(['VAT']), $this->rules());
    }

    public function testRejectsTaxRuleSetGap(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('continuous without gaps');
        (new BillingDefinitionParser())->parse($this->definitionWithRuleSets([
            ['validFrom' => null, 'validTo' => '2026-02-01T00:00:00+01:00', 'rules' => $this->ruleDocuments()],
            ['validFrom' => '2026-03-01T00:00:00+01:00', 'validTo' => null, 'rules' => $this->ruleDocuments()],
        ]));
    }

    public function testRejectsTaxRuleSetOverlap(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('must not overlap');
        (new BillingDefinitionParser())->parse($this->definitionWithRuleSets([
            ['validFrom' => null, 'validTo' => '2026-03-01T00:00:00+01:00', 'rules' => $this->ruleDocuments()],
            ['validFrom' => '2026-02-01T00:00:00+01:00', 'validTo' => null, 'rules' => $this->ruleDocuments()],
        ]));
    }

    /** @return list<TaxRuleDefinition> */
    private function rules(): array
    {
        return [
            new TaxRuleDefinition('EXCISE', 'PER_QUANTITY', ['ENERGY_PURCHASE'], '0.005', 'PLN/kWh'),
            new TaxRuleDefinition('VAT', 'PERCENTAGE', ['ENERGY_PURCHASE'], '0.23', null, 'CURRENT_SUBTOTAL'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ruleDocuments(): array
    {
        return [
            ['id' => 'EXCISE', 'type' => 'PER_QUANTITY', 'appliesToKinds' => ['ENERGY_PURCHASE'], 'rate' => '0.005', 'unit' => 'PLN/kWh'],
            ['id' => 'VAT', 'type' => 'PERCENTAGE', 'appliesToKinds' => ['ENERGY_PURCHASE'], 'rate' => '0.23', 'base' => 'CURRENT_SUBTOTAL'],
        ];
    }

    /** @param list<array<string, mixed>> $sets @return array<string, mixed> */
    private function definitionWithRuleSets(array $sets): array
    {
        return [
            'version' => 1,
            'currency' => 'PLN',
            'taxRuleSets' => $sets,
            'periods' => [[
                'components' => [[
                    'id' => 'energy-purchase', 'kind' => 'ENERGY_PURCHASE', 'category' => 'ENERGY',
                    'quantity' => ['type' => 'ACTIVE_ENERGY_IMPORT'], 'rate' => ['type' => 'CONSTANT', 'value' => '0.5'],
                    'taxTreatment' => ['included' => []],
                ]],
            ]],
        ];
    }
}
