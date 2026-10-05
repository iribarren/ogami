<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\InvalidDiceExpression;

enum Operator: string
{
    case Plus = '+';
    case Minus = '-';
    case Times = '*';
    case DividedBy = '/';

    /**
     * Applies the operator; division is integer division rounding down (floor).
     *
     * @param string $notation the notation of the operation, for error messages
     *
     * @throws InvalidDiceExpression on division by zero or when the result does not fit an integer
     */
    public function apply(int $left, int $right, string $notation): int
    {
        $exact = match ($this) {
            self::Plus => (float) $left + $right,
            self::Minus => (float) $left - $right,
            self::Times => (float) $left * $right,
            self::DividedBy => 0.0,
        };

        // Rejecting |result| >= 2^63 keeps every intermediate value strictly inside the integer range.
        if (abs($exact) >= (float) \PHP_INT_MAX) {
            throw InvalidDiceExpression::resultTooLarge($notation);
        }

        return match ($this) {
            self::Plus => $left + $right,
            self::Minus => $left - $right,
            self::Times => $left * $right,
            self::DividedBy => self::floorDivide($left, $right, $notation),
        };
    }

    private static function floorDivide(int $left, int $right, string $notation): int
    {
        if (0 === $right) {
            throw InvalidDiceExpression::divisionByZero($notation);
        }

        $quotient = intdiv($left, $right);
        if (0 !== $left % $right && ($left < 0) !== ($right < 0)) {
            --$quotient;
        }

        return $quotient;
    }
}
