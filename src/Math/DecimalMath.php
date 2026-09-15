<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Math;

interface DecimalMath
{
    public function add(string $left, string $right, int $scale = 9): string;

    public function multiply(string $left, string $right, int $scale = 9): string;

    public function divide(string $left, string $right, int $scale = 9): string;
}
