<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\ValueResolver;

final readonly class Constant implements Node
{
    public function __construct(
        private int $value,
    ) {
    }

    public function evaluate(ValueResolver $resolver): int
    {
        return $this->value;
    }

    public function references(): array
    {
        return [];
    }
}
