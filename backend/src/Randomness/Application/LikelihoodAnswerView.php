<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Randomness\Domain\Oracle\LikelihoodAnswer;

/**
 * The answer of a likelihood oracle, with the roll and the target it was compared with.
 */
final readonly class LikelihoodAnswerView
{
    /**
     * @param string   $answer          "exceptional_yes", "yes", "no" or "exceptional_no"
     * @param int      $effectiveTarget the level's target shifted by the chaos factor and clamped to 0…sides
     * @param int|null $chaosFactor     the chaos factor used (the neutral one when none was given); null when the oracle has no chaos
     */
    public function __construct(
        public string $answer,
        public int $roll,
        public int $sides,
        public int $effectiveTarget,
        public string $likelihood,
        public string $likelihoodLabel,
        public ?int $chaosFactor,
    ) {
    }

    public static function fromAnswer(LikelihoodAnswer $answer): self
    {
        return new self(
            $answer->answer()->value,
            $answer->roll(),
            $answer->sides(),
            $answer->effectiveTarget(),
            $answer->levelKey(),
            $answer->levelLabel(),
            $answer->chaosFactor(),
        );
    }
}
