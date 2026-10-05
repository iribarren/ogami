<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\DiceGroup;

/**
 * The value of an evaluated node and the dice groups rolled to get it, left to right.
 */
final readonly class Outcome
{
    /**
     * @param list<DiceGroup> $groups
     */
    public function __construct(
        public int $value,
        public array $groups = [],
    ) {
    }
}
