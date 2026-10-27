<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * Which dice of a pool a die belongs to (ADR 0044): the regular dice, or the Hunger dice rolled
 * inside the pool, which a push never re-rolls. The value is what the journal stores.
 */
enum DieGroup: string
{
    case Pool = 'pool';
    case Hunger = 'hunger';
}
