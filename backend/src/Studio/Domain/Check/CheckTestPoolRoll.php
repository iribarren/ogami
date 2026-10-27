<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Randomness\Domain\PoolRoll;

/**
 * The pool a test roll rolled: the dice, the count the check asked for before the situational
 * modifier (a number, or what its formula gave for the samples) and that formula's text.
 */
final readonly class CheckTestPoolRoll
{
    /**
     * @param ?string $countFormula  null when the check's count is a number
     * @param ?string $hungerFormula null when the check's Hunger count is a number, or it has none
     */
    public function __construct(
        public PoolRoll $roll,
        public int $count,
        public ?string $countFormula,
        public ?string $hungerFormula = null,
    ) {
    }
}
