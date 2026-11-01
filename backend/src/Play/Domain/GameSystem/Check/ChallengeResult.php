<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Randomness\Domain\ChallengeRoll;

/**
 * What the challenge dice of a check produced (ADR 0049): the dice rolled, the score they were
 * compared against (capped), the momentum value that was read and whether it cancelled the action die.
 * The check's total is the number of dice the score beat.
 */
final readonly class ChallengeResult
{
    public function __construct(
        public ChallengeRoll $roll,
        public int $score,
        public ?int $momentum,
        public bool $cancelled,
    ) {
    }
}
