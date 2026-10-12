<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * The outcome of asking a likelihood oracle: the answer, the roll on 1d<sides> and the effective
 * target it was compared with, the level asked, the chaos factor used and, when the roll triggered
 * a random event, the key of the table to roll.
 */
final readonly class LikelihoodAnswer
{
    public function __construct(
        private YesNoAnswer $answer,
        private int $roll,
        private int $sides,
        private int $effectiveTarget,
        private string $levelKey,
        private string $levelLabel,
        private ?int $chaosFactor,
        private ?string $randomEvent = null,
    ) {
    }

    public function answer(): YesNoAnswer
    {
        return $this->answer;
    }

    public function roll(): int
    {
        return $this->roll;
    }

    public function sides(): int
    {
        return $this->sides;
    }

    /**
     * The level's target shifted by the chaos factor and clamped to 0…sides.
     */
    public function effectiveTarget(): int
    {
        return $this->effectiveTarget;
    }

    public function levelKey(): string
    {
        return $this->levelKey;
    }

    public function levelLabel(): string
    {
        return $this->levelLabel;
    }

    /**
     * The chaos factor used (the neutral one when none was given); null when the oracle has no chaos.
     */
    public function chaosFactor(): ?int
    {
        return $this->chaosFactor;
    }

    /**
     * The key of the oracle table to roll when this roll is a double whose digit is at most the chaos
     * factor used and the oracle has a random event rule; null otherwise.
     */
    public function randomEvent(): ?string
    {
        return $this->randomEvent;
    }
}
