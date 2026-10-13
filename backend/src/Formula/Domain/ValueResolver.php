<?php

declare(strict_types=1);

namespace App\Formula\Domain;

/**
 * Supplies the values a formula reads. Play implements it over a Character's sheet and a Campaign's trackers;
 * the kernel knows nothing about either.
 */
interface ValueResolver
{
    /**
     * @throws FormulaEvaluationFailed {@see FormulaEvaluationFailed::unknownReference()} when there is no such value
     */
    public function value(Reference $reference): int;
}
