<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Formula\Domain\ValueResolver;

/**
 * What a check's formulas read: the Character's sheet, and {check:difficulty} when a difficulty
 * applies. Any other check reference has no value, and neither has the difficulty of a check
 * that declares none.
 */
final readonly class CheckValues implements ValueResolver
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
