<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

final class InvalidDiceExpression extends \DomainException
{
    public static function empty(): self
    {
        return new self('The dice expression is empty; expected dice notation such as "2d6+1".');
    }

    public static function tooLong(int $length, int $max): self
    {
        return new self(\sprintf('A dice expression has at most %d characters, %d given.', $max, $length));
    }

    public static function unexpectedCharacter(string $character, int $position): self
    {
        return new self(\sprintf(
            'Unexpected "%s" at position %d; a dice expression only contains numbers, dice ("d", "%%"), selectors ("kh", "kl", "dh", "dl", "k"), "+", "-", "*", "/" and parentheses.',
            $character,
            $position,
        ));
    }

    public static function unexpectedToken(string $token, int $position, string $expected): self
    {
        return new self(\sprintf('Unexpected "%s" at position %d; expected %s.', $token, $position, $expected));
    }

    public static function unexpectedEnd(string $expected): self
    {
        return new self(\sprintf('Unexpected end of the dice expression; expected %s.', $expected));
    }

    public static function integerOutOfRange(string $integer, int $max): self
    {
        return new self(\sprintf('A number in a dice expression is between 0 and %d, %s given.', $max, $integer));
    }

    public static function diceCountOutOfRange(int $count, int $max): self
    {
        return new self(\sprintf('A dice group rolls between 1 and %d dice, %d given.', $max, $count));
    }

    public static function tooManyDice(int $count, int $max): self
    {
        return new self(\sprintf('A dice expression rolls at most %d dice in total, %d given.', $max, $count));
    }

    public static function sidesOutOfRange(int $sides, int $min, int $max): self
    {
        return new self(\sprintf('A die has between %d and %d sides, %d given.', $min, $max, $sides));
    }

    public static function keepCountOutOfRange(string $selector, int $keep, int $dice): self
    {
        return new self(\sprintf('"%s" keeps between 1 and %d dice, %d given.', $selector, $dice, $keep));
    }

    public static function dropCountOutOfRange(string $selector, int $drop, int $dice): self
    {
        return new self(\sprintf('"%s" drops between 0 and %d dice, %d given.', $selector, $dice - 1, $drop));
    }

    public static function divisionByZero(string $notation): self
    {
        return new self(\sprintf('"%s" divides by zero.', $notation));
    }

    public static function resultTooLarge(string $notation): self
    {
        return new self(\sprintf('"%s" is too large to compute.', $notation));
    }
}
