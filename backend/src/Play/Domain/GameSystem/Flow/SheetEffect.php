<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

use App\Formula\Domain\Formula;
use App\Randomness\Domain\DiceExpression;

/**
 * Changes a sheet field of the Character the step names (ADR 0027): the value of a number field or
 * the current value of a track field. The value is a literal, a Formula over that Character's sheet
 * and the Trackers, or dice rolled when the effect applies. Only a check step's outcomes and a
 * character step's own effects hold one.
 *
 * Mark and clear change a conditions field instead (ADR 0041): the value is the option key to mark
 * or clear, and a clear without one empties the field. The other operations never take an option
 * key, and mark and clear never take a number.
 */
final readonly class SheetEffect implements Effect
{
    public function __construct(
        public string $field,
        public SheetOperation $op,
        public int|Formula|DiceExpression|string|null $value = null,
    ) {
    }
}
