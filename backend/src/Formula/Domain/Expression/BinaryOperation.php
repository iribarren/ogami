<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\ValueResolver;

final readonly class BinaryOperation implements Node
{
    public function __construct(
        private Node $left,
        private Operator $operator,
        private Node $right,
    ) {
    }

    public function evaluate(ValueResolver $resolver): int
    {
        return $this->operator->apply($this->left->evaluate($resolver), $this->right->evaluate($resolver));
    }

    public function references(): array
    {
        return [...$this->left->references(), ...$this->right->references()];
    }
}
