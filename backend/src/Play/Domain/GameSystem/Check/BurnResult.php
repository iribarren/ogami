<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * What burning momentum on a challenge check produced (ADR 0049): the momentum value that was read,
 * the score it became (capped), the number of challenge dice that score beats, the Outcome band that
 * total falls in and the special result, which the burn cannot change as no die is re-rolled.
 */
final readonly class BurnResult
{
    public function __construct(
        public int $momentum,
        public int $score,
        public int $total,
        public CheckBand $band,
        public ?SpecialResult $special = null,
    ) {
    }
}
