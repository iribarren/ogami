<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\ValueResolver;

final readonly class Negation implements Node
{
    public function __construct(
        private Node $operand,
    ) {
    }

    public function evaluate(ValueResolver $resolver): int
    {
        // Cannot overflow: every operand is within the allowed range, which is symmetric.
        return -$this->operand->evaluate($resolver);
    }

    public function references(): array
    {
        return $this->operand->references();
    }
}
