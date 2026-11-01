<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Formula\Domain\Reference;
use App\Play\Domain\GameSystem\Check\CheckChallenge;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;

/**
 * The challenge dice of a check as a player rolls it (ADR 0049): how many, their sides, the cap of the
 * score and the label of the momentum field that can cancel the action die.
 */
final readonly class CheckChallengeView
{
    public function __construct(
        public int $count,
        public int $sides,
        public ?int $scoreMax,
        public ?string $momentumLabel,
    ) {
    }

    public static function of(CheckChallenge $challenge, ReferenceLabels $labels): self
    {
        return new self(
            $challenge->count,
            $challenge->sides,
            $challenge->scoreMax,
            null === $challenge->momentum ? null : $labels->label(Reference::field($challenge->momentum)),
        );
    }
}
