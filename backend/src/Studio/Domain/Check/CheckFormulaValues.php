<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Formula\Domain\ValueResolver;

/**
 * What a check's formulas read in a test roll: the sample sheet, and {check:difficulty} when a
 * difficulty applies. Any other check reference has no value, and neither has the difficulty of a
 * check that declares none (as Play's CheckValues).
 */
final readonly class CheckFormulaValues implements ValueResolver
{
    public function __construct(
        private ValueResolver $sheet,
        private ?int $difficulty,
    ) {
    }

    public function value(Reference $reference): int
    {
        if (ReferenceKind::Check !== $reference->kind) {
            return $this->sheet->value($reference);
        }

        return 'difficulty' === $reference->key && null !== $this->difficulty ? $this->difficulty : throw FormulaEvaluationFailed::unknownReference($reference);
    }
}
