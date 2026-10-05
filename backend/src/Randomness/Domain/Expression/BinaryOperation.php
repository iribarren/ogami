<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\RandomNumberGenerator;

final readonly class BinaryOperation implements Node
{
    public function __construct(
        private Node $left,
        private Operator $operator,
        private Node $right,
    ) {
    }

    public function evaluate(RandomNumberGenerator $random): Outcome
    {
        $left = $this->left->evaluate($random);
        $right = $this->right->evaluate($random);

        return new Outcome(
            $this->operator->apply($left->value, $right->value, $this->notation()),
            [...$left->groups, ...$right->groups],
        );
    }

    public function notation(): string
    {
        return $this->left->notation().$this->operator->value.$this->right->notation();
    }

    public function diceCount(): int
    {
        return $this->left->diceCount() + $this->right->diceCount();
    }
}
