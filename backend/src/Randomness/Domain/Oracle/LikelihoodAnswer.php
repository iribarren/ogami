<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * The outcome of asking a likelihood oracle: the answer, the roll on 1d<sides> and the effective
 * target it was compared with, the level asked and the chaos factor used.
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
}
