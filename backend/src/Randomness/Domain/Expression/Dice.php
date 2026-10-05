<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\DiceGroup;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Randomness\Domain\RolledDie;

/**
 * A dice term such as "4d6kh3": roll count dice with the given sides, then
 * optionally keep or drop some of them.
 */
final readonly class Dice implements Node
{
    /**
     * @throws InvalidDiceExpression when a count, the sides or the selection is out of range
     */
    public function __construct(
        private int $count,
        private int $sides,
        private ?Selector $selector = null,
        private int $selectCount = 0,
    ) {
        if ($count < 1 || $count > DiceExpression::MAX_DICE) {
            throw InvalidDiceExpression::diceCountOutOfRange($count, DiceExpression::MAX_DICE);
        }

        if ($sides < DiceExpression::MIN_SIDES || $sides > DiceExpression::MAX_SIDES) {
            throw InvalidDiceExpression::sidesOutOfRange($sides, DiceExpression::MIN_SIDES, DiceExpression::MAX_SIDES);
        }

        if ($selector?->keeps() && ($selectCount < 1 || $selectCount > $count)) {
            throw InvalidDiceExpression::keepCountOutOfRange($selector->value, $selectCount, $count);
        }

        if ($selector instanceof Selector && !$selector->keeps() && ($selectCount < 0 || $selectCount >= $count)) {
            throw InvalidDiceExpression::dropCountOutOfRange($selector->value, $selectCount, $count);
        }
    }

    public function evaluate(RandomNumberGenerator $random): Outcome
    {
        $values = [];
        for ($i = 0; $i < $this->count; ++$i) {
            $values[] = $random->between(1, $this->sides);
        }

        $kept = $this->selector?->select($values, $this->selectCount) ?? array_fill(0, $this->count, true);
        $dice = array_map(
            static fn (int $value, bool $isKept): RolledDie => $isKept ? RolledDie::kept($value) : RolledDie::dropped($value),
            $values,
            $kept,
        );

        $group = new DiceGroup($this->notation(), $this->sides, $dice);

        return new Outcome($group->subtotal(), [$group]);
    }

    public function notation(): string
    {
        return \sprintf('%dd%d', $this->count, $this->sides)
            .($this->selector instanceof Selector ? $this->selector->value.$this->selectCount : '');
    }

    public function diceCount(): int
    {
        return $this->count;
    }
}
