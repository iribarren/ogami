<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ValueResolver;

/**
 * A node of a parsed formula tree.
 */
interface Node
{
    /**
     * @throws FormulaEvaluationFailed on division by zero, a result outside the allowed range or a value the resolver lacks
     */
    public function evaluate(ValueResolver $resolver): int;

    /**
     * Every reference of the subtree from left to right, repeats included.
     *
     * @return list<Reference>
     */
    public function references(): array;
}
