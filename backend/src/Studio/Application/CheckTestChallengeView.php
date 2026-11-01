<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Check\CheckTestChallengeRoll;

/**
 * The challenge dice of a test roll (ADR 0049): the action die (none on a progress roll), the sides, the
 * score cap and the score that applied, the momentum and the field it came from, whether negative
 * momentum cancelled the action die, each challenge die with whether the score beat it, and the burn.
 */
final readonly class CheckTestChallengeView
{
    /**
     * @param ?int                            $momentum      null when the check names no momentum
     * @param ?string                         $momentumField null when the check names no momentum
     * @param list<CheckTestChallengeDieView> $dice          in roll order
     * @param ?int                            $burnMomentum  the momentum spent; null unless the roll was burned
     * @param ?int                            $burnScore     the score the burn made; null unless the roll was burned
     */
    public function __construct(
        public ?int $actionDie,
        public int $sides,
        public ?int $scoreMax,
        public int $score,
        public ?int $momentum,
        public ?string $momentumField,
        public bool $cancelled,
        public array $dice,
        public ?int $burnMomentum,
        public ?int $burnScore,
    ) {
    }

    public static function fromChallengeRoll(CheckTestChallengeRoll $challenge): self
    {
        $roll = $challenge->roll;

        return new self(
            $challenge->actionDie,
            $roll->dice()->sides,
            $challenge->scoreMax,
            $challenge->score,
            $challenge->momentum,
            $challenge->momentumField,
            $challenge->cancelled,
            array_map(static fn (int $face): CheckTestChallengeDieView => new CheckTestChallengeDieView($face, $face < $challenge->score), $roll->faces()),
            $challenge->burn?->momentum,
            $challenge->burn?->score,
        );
    }
}
