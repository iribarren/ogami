<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\Check\Check;

/**
 * A Check of the campaign's pinned release as a player rolls it: the dice and the difficulty it
 * asks for. The modifier formula and the Outcome bands stay in the release; the result names the band.
 */
final readonly class CheckView
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint,
        public string $dice,
        public ?CheckDifficultyView $difficulty,
    ) {
    }

    public static function of(Check $check): self
    {
        $difficulty = $check->difficulty;

        return new self(
            $check->key,
            $check->label,
            $check->hint,
            $check->dice->notation(),
            $difficulty instanceof \App\Play\Domain\GameSystem\Check\CheckDifficulty ? new CheckDifficultyView($difficulty->label, $difficulty->default, $difficulty->min, $difficulty->max) : null,
        );
    }
}
