<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Randomness\Domain\PoolRoll;

/**
 * What rolling a pool check produced: the count the release gave (a number, or the formula's value
 * over the sheet), the dice rolled, and the values the count formula read, in the order it first
 * names them. The size rolled is the roll's own.
 */
final readonly class PoolResult
{
    /**
     * @param list<ModifierTerm> $terms empty when the count is a number
     */
    public function __construct(
        public int $count,
        public PoolRoll $roll,
        public array $terms = [],
    ) {
    }
}
