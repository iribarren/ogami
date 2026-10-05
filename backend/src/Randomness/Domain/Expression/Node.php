<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * A node of a parsed dice expression tree.
 */
interface Node
{
    /**
     * @throws InvalidDiceExpression when the evaluation divides by zero or overflows
     */
    public function evaluate(RandomNumberGenerator $random): Outcome;

    public function notation(): string;

    public function diceCount(): int;
}
