<?php

declare(strict_types=1);

namespace App\Randomness\Application;

/**
 * One die of a roll: the face it landed on and whether it counts towards the total.
 */
final readonly class RolledDieView
{
    public function __construct(
        public int $value,
        public bool $kept,
    ) {
    }
}
