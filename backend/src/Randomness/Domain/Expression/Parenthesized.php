<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\RandomNumberGenerator;

/**
 * A parenthesized sub-expression, kept as a node so the notation round-trips.
 */
final readonly class Parenthesized implements Node
{
    public function __construct(
        private Node $inner,
    ) {
    }

    public function evaluate(RandomNumberGenerator $random): Outcome
    {
        return $this->inner->evaluate($random);
    }

    public function notation(): string
    {
        return '('.$this->inner->notation().')';
    }

    public function diceCount(): int
    {
        return $this->inner->diceCount();
    }
}
