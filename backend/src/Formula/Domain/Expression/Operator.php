<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;

enum Operator: string
{
    case Plus = '+';
    case Minus = '-';
    case Times = '*';
    case DividedBy = '/';

    /**
     * Applies the operator to operands within ±Formula::MAX_MAGNITUDE; division is integer division
     * rounding down (floor).
     *
     * @throws FormulaEvaluationFailed on division by zero or a result outside ±Formula::MAX_MAGNITUDE
     */
    public function apply(int $left, int $right): int
    {
        // Operands are at most 10^9, so even a product (10^18) is exact in a 64-bit integer.
        $result = match ($this) {
            self::Plus => $left + $right,
            self::Minus => $left - $right,
            self::Times => $left * $right,
            self::DividedBy => self::floorDivide($left, $right),
        };

        if (abs($result) > Formula::MAX_MAGNITUDE) {
            throw FormulaEvaluationFailed::overflow();
        }

        return $result;
    }

    private static function floorDivide(int $left, int $right): int
    {
        if (0 === $right) {
            throw FormulaEvaluationFailed::divisionByZero();
        }

        $quotient = intdiv($left, $right);
        if (0 !== $left % $right && ($left < 0) !== ($right < 0)) {
            --$quotient;
        }

        return $quotient;
    }
}
