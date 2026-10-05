<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

final class InvalidDiceExpression extends \DomainException
{
    public static function unparsable(string $notation): self
    {
        return new self(\sprintf('"%s" is not a dice expression; expected NdM, NdM+K or NdM-K (e.g. "2d6+1").', $notation));
    }

    public static function diceCountOutOfRange(int $count, int $max): self
    {
        return new self(\sprintf('A dice expression rolls between 1 and %d dice, %d given.', $max, $count));
    }

    public static function sidesOutOfRange(int $sides, int $min, int $max): self
    {
        return new self(\sprintf('A die has between %d and %d sides, %d given.', $min, $max, $sides));
    }
}
