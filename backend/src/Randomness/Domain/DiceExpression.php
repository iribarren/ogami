<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

use App\Randomness\Domain\Expression\Node;
use App\Randomness\Domain\Expression\Parser;

/**
 * A parsed dice notation such as "4d6kh3", "2d6+1d4-2" or "(1d6+2)*3".
 *
 * Grammar (case-insensitive, whitespace ignored):
 *
 *     expression := term (("+" | "-") term)*
 *     term       := unary (("*" | "/") unary)*
 *     unary      := "-" unary | primary
 *     primary    := integer | dice | "(" expression ")"
 *     dice       := [count] "d" (sides | "%") [selector]
 *     selector   := ("kh" | "kl" | "dh" | "dl" | "k") count
 *
 * Division rounds down. Rolling evaluates the dice from left to right.
 */
final readonly class DiceExpression implements \Stringable
{
    public const int MAX_LENGTH = 100;
    public const int MAX_DICE = 100;
    public const int MIN_SIDES = 2;
    public const int MAX_SIDES = 1000;
    public const int MAX_INTEGER = 1_000_000;

    private function __construct(
        private Node $root,
    ) {
    }

    /**
     * @throws InvalidDiceExpression
     */
    public static function fromString(string $notation): self
    {
        if (\strlen($notation) > self::MAX_LENGTH) {
            throw InvalidDiceExpression::tooLong(\strlen($notation), self::MAX_LENGTH);
        }

        if ('' === trim($notation)) {
            throw InvalidDiceExpression::empty();
        }

        $root = Parser::parse($notation);
        if ($root->diceCount() > self::MAX_DICE) {
            throw InvalidDiceExpression::tooManyDice($root->diceCount(), self::MAX_DICE);
        }

        return new self($root);
    }

    /**
     * The normalized notation: lower case, no whitespace, "d" as "1d", "d%" as "d100" and "k" as "kh".
     */
    public function notation(): string
    {
        return $this->root->notation();
    }

    public function __toString(): string
    {
        return $this->notation();
    }

    /**
     * How many dice a roll of this expression rolls.
     */
    public function diceCount(): int
    {
        return $this->root->diceCount();
    }

    /**
     * Whether the expression is exactly one die: "1dN", with no selector and no arithmetic.
     */
    public function rollsOneDie(): bool
    {
        return null !== $this->sidesOfOneDie();
    }

    /**
     * The sides of the die when the expression is exactly one die (see rollsOneDie), null otherwise.
     * A percentile die has 100 sides, as the normalized notation says.
     */
    public function sidesOfOneDie(): ?int
    {
        if (1 !== preg_match('/^1d(\d+)$/', $this->notation(), $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @throws InvalidDiceExpression when evaluation divides by zero or overflows
     */
    public function roll(RandomNumberGenerator $random): Roll
    {
        $outcome = $this->root->evaluate($random);

        return new Roll($this, $outcome->value, $outcome->groups);
    }
}
