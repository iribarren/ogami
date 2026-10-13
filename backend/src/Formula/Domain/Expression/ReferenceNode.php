<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ValueResolver;

final readonly class ReferenceNode implements Node
{
    public function __construct(
        private Reference $reference,
    ) {
    }

    public function evaluate(ValueResolver $resolver): int
    {
        $value = $resolver->value($this->reference);
        if (abs($value) > Formula::MAX_MAGNITUDE) {
            throw FormulaEvaluationFailed::overflow();
        }

        return $value;
    }

    public function references(): array
    {
        return [$this->reference];
    }
}
