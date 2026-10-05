<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * The dice rolled for one dice term of an expression, such as "4d6kh3".
 */
final readonly class DiceGroup
{
    private int $subtotal;

    /**
     * @param list<RolledDie> $dice every die rolled, in roll order, dropped ones included
     */
    public function __construct(
        private string $notation,
        private int $sides,
        private array $dice,
    ) {
        $this->subtotal = array_sum(array_map(
            static fn (RolledDie $die): int => $die->isKept() ? $die->value() : 0,
            $dice,
        ));
    }

    public function notation(): string
    {
        return $this->notation;
    }

    public function sides(): int
    {
        return $this->sides;
    }

    /**
     * @return list<RolledDie>
     */
    public function dice(): array
    {
        return $this->dice;
    }

    /**
     * The sum of the kept dice.
     */
    public function subtotal(): int
    {
        return $this->subtotal;
    }
}
