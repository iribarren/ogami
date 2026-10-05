<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\RandomNumberGenerator;

final readonly class Constant implements Node
{
    public function __construct(
        private int $value,
    ) {
    }

    public function evaluate(RandomNumberGenerator $random): Outcome
    {
        return new Outcome($this->value);
    }

    public function notation(): string
    {
        return (string) $this->value;
    }

    public function diceCount(): int
    {
        return 0;
    }
}
