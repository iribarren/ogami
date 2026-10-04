<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * The result of evaluating a dice expression: the individual dice and the total.
 */
final readonly class Roll
{
    private int $total;

    /**
     * @param list<int> $dice
     */
    public function __construct(
        private DiceExpression $expression,
        private array $dice,
    ) {
        $this->total = array_sum($dice) + $expression->modifier();
    }

    public function expression(): DiceExpression
    {
        return $this->expression;
    }

    /**
     * @return list<int>
     */
    public function dice(): array
    {
        return $this->dice;
    }

    public function total(): int
    {
        return $this->total;
    }
}
