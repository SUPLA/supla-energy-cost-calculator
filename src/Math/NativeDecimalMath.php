<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Math;

/**
 * Dependency-free default intended for simulations.
 * Replace through DecimalMath for invoice-grade exact decimal arithmetic.
 */
final class NativeDecimalMath implements DecimalMath
{
    public function add(string $left, string $right, int $scale = 9): string
    {
        return $this->format((float)$left + (float)$right, $scale);
    }

    public function multiply(string $left, string $right, int $scale = 9): string
    {
        return $this->format((float)$left * (float)$right, $scale);
    }

    public function divide(string $left, string $right, int $scale = 9): string
    {
        if ((float)$right === 0.0) {
            throw new \DivisionByZeroError();
        }

        return $this->format((float)$left / (float)$right, $scale);
    }

    private function format(float $value, int $scale): string
    {
        $formatted = number_format($value, $scale, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }
}
