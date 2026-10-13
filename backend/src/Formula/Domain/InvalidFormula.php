<?php

declare(strict_types=1);

namespace App\Formula\Domain;

final class InvalidFormula extends \DomainException
{
    /**
     * The keys around the cycle when derived values depend on each other, from its first key back to the same key.
     *
     * @var list<string>
     */
    public array $cycle = [];

    public static function empty(): self
    {
        return new self('The formula is empty; expected a formula such as "{str}+1".');
    }

    public static function tooLong(int $length, int $max): self
    {
        return new self(\sprintf('A formula has at most %d characters, %d given.', $max, $length));
    }

    public static function unexpectedCharacter(string $character, int $position): self
    {
        return new self(\sprintf(
            'Unexpected "%s" at position %d; a formula only contains numbers, references in braces, "min", "max", "+", "-", "*", "/", parentheses and commas.',
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
        return new self(\sprintf('Unexpected end of the formula; expected %s.', $expected));
    }

    public static function integerOutOfRange(string $integer, int $max): self
    {
        return new self(\sprintf('A number in a formula is between 0 and %d, %s given.', $max, $integer));
    }

    public static function unknownName(string $name, int $position): self
    {
        return new self(\sprintf('Unknown name "%s" at position %d; write field references in braces, as in "{str}", and use "min" or "max" in lower case.', $name, $position));
    }

    public static function invalidReference(string $reference, int $position): self
    {
        return new self(\sprintf(
            'Invalid reference "%s" at position %d; expected "{key}", "{key.max}", "{tracker:key}" or "{check:key}" with a key of 1 to 64 characters of a-z, 0-9 and "-", and no spaces.',
            $reference,
            $position,
        ));
    }

    public static function invalidKey(string $key): self
    {
        return new self(\sprintf('"%s" is not a valid key; a key has 1 to 64 characters of a-z, 0-9 and "-".', $key));
    }

    public static function unclosedReference(int $position): self
    {
        return new self(\sprintf('Unclosed "{" at position %d; a reference ends with "}".', $position));
    }

    public static function argumentCountOutOfRange(string $function, int $min, int $max, ?int $given): self
    {
        return new self(\sprintf('"%s" takes between %d and %d arguments, %s given.', $function, $min, $max, null === $given ? 'more than '.$max : (string) $given));
    }

    public static function tooDeep(int $max): self
    {
        return new self(\sprintf('A formula nests at most %d levels deep.', $max));
    }

    /**
     * @param list<string> $path the keys around the cycle, from its first key back to the same key: ["a", "b", "a"]
     */
    public static function cycle(array $path): self
    {
        $invalid = new self(\sprintf('Derived values depend on each other in a cycle: %s.', implode(' -> ', $path)));
        $invalid->cycle = $path;

        return $invalid;
    }
}
