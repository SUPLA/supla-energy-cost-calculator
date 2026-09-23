<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\CalculationException;
use Supla\EnergyCostCalculator\Exception\CostPlanDefinitionException;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Exception\EnergyCostCalculatorException;
use Supla\EnergyCostCalculator\Exception\HolidayCalendarCoverageException;
use Supla\EnergyCostCalculator\Exception\HolidayCalendarNotFoundException;
use Supla\EnergyCostCalculator\Exception\IntervalCrossesBillingPeriodException;
use Supla\EnergyCostCalculator\Exception\InvalidHolidayCalendarException;
use Supla\EnergyCostCalculator\Exception\InvalidTariffPresetException;
use Supla\EnergyCostCalculator\Exception\MissingBillingPeriodException;
use Supla\EnergyCostCalculator\Exception\MissingReferenceDataException;
use Supla\EnergyCostCalculator\Exception\TariffPresetCompilationException;
use Supla\EnergyCostCalculator\Exception\TariffPresetNotFoundException;

final class ExceptionHierarchyTest extends TestCase
{
    #[DataProvider('libraryExceptionClasses')]
    public function testLibraryExceptionsShareBaseClass(string $exceptionClass): void
    {
        self::assertTrue(is_a($exceptionClass, EnergyCostCalculatorException::class, true));
    }

    /** @return iterable<string, array{string}> */
    public static function libraryExceptionClasses(): iterable
    {
        yield CalculationException::class => [CalculationException::class];
        yield CostPlanDefinitionException::class => [CostPlanDefinitionException::class];
        yield DefinitionException::class => [DefinitionException::class];
        yield HolidayCalendarCoverageException::class => [HolidayCalendarCoverageException::class];
        yield HolidayCalendarNotFoundException::class => [HolidayCalendarNotFoundException::class];
        yield IntervalCrossesBillingPeriodException::class => [IntervalCrossesBillingPeriodException::class];
        yield InvalidHolidayCalendarException::class => [InvalidHolidayCalendarException::class];
        yield InvalidTariffPresetException::class => [InvalidTariffPresetException::class];
        yield MissingBillingPeriodException::class => [MissingBillingPeriodException::class];
        yield MissingReferenceDataException::class => [MissingReferenceDataException::class];
        yield TariffPresetCompilationException::class => [TariffPresetCompilationException::class];
        yield TariffPresetNotFoundException::class => [TariffPresetNotFoundException::class];
    }
}
