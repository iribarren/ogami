<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * The result of evaluating a dice expression: the total and every dice group rolled.
 */
final readonly class Roll
{
    /**
     * @param list<DiceGroup> $groups the dice groups in notation order, left to right
     */
    public function __construct(
        private DiceExpression $expression,
        private int $total,
        private array $groups,
    ) {
    }

    public function expression(): DiceExpression
    {
        return $this->expression;
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * @return list<DiceGroup>
     */
    public function groups(): array
    {
        return $this->groups;
    }
}
