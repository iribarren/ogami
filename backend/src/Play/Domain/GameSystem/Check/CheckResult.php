<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Randomness\Domain\Roll;

/**
 * What performing a check produced: the dice rolled, the modifier formula, the situational
 * modifier and the difficulty that applied, their total, and the Outcome band the total falls in.
 * The modifier terms are the values the modifier formula read, in the order it first names them.
 *
 * A pool check rolls no dice expression: it has a pool result and no roll, its total is the number
 * of successes, and it has no modifier (the situational modifier changed the size of the pool).
 */
final readonly class CheckResult
{
    /**
     * @param list<ModifierTerm> $modifierTerms empty when the check has no modifier formula
     */
    public function __construct(
        public ?Roll $roll,
        public int $modifier,
        public int $situational,
        public ?int $difficulty,
        public int $total,
        public CheckBand $band,
        public array $modifierTerms = [],
        public ?PoolResult $pool = null,
    ) {
    }
}
