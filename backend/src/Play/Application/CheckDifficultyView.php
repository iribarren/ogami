<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The Check difficulty a check declares: what the player sets at roll time, its default and its limits.
 */
final readonly class CheckDifficultyView
{
    public function __construct(
        public string $label,
        public int $default,
        public ?int $min,
        public ?int $max,
    ) {
    }
}
