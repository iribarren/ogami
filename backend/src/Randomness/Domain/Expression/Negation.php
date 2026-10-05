<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\RandomNumberGenerator;

final readonly class Negation implements Node
{
    public function __construct(
        private Node $operand,
    ) {
    }

    public function evaluate(RandomNumberGenerator $random): Outcome
    {
        $outcome = $this->operand->evaluate($random);

        // Cannot overflow: Operator rejects any result outside ]PHP_INT_MIN, PHP_INT_MAX[.
        return new Outcome(-$outcome->value, $outcome->groups);
    }

    public function notation(): string
    {
        return '-'.$this->operand->notation();
    }

    public function diceCount(): int
    {
        return $this->operand->diceCount();
    }
}
