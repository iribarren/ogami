<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * One challenge die of a test roll: its face, and whether the score beat it (a tie goes to the die).
 */
final readonly class CheckTestChallengeDieView
{
    public function __construct(
        public int $face,
        public bool $beaten,
    ) {
    }
}
