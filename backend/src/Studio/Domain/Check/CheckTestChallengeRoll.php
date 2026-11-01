<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Randomness\Domain\ChallengeRoll;

/**
 * The challenge dice a test roll rolled (ADR 0049), with what the score did with them: the action die
 * (the total of the action dice; null on a progress roll), the score after the cap and, when negative
 * momentum cancelled the action die, `cancelled`. A burned roll holds the burned score and the burn.
 */
final readonly class CheckTestChallengeRoll
{
    /**
     * @param ?int    $momentum      the value of the momentum field for the samples; null when the check names none
     * @param ?string $momentumField the key of that field
     */
    public function __construct(
        public ChallengeRoll $roll,
        public ?int $actionDie,
        public int $score,
        public ?int $scoreMax,
        public ?int $momentum,
        public ?string $momentumField,
        public bool $cancelled,
        public ?CheckTestBurn $burn = null,
    ) {
    }
}
