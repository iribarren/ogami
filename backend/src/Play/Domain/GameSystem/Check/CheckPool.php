<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\InvalidDicePool;

/**
 * The pool a check rolls instead of a dice expression (ADR 0041): how many dice, read from the
 * release as a number or a formula over the sheet, their sides and the face a die succeeds from.
 * The size actually rolled is the count plus the situational modifier, kept in 1..100.
 */
final readonly class CheckPool
{
    /**
     * @param int|Formula $count a number in 1..100, or a formula over the sheet
     *
     * @throws \InvalidArgumentException when the count, the sides or the success threshold is out of bounds
     */
    public function __construct(
        public int|Formula $count,
        public int $sides,
        public int $successAt,
    ) {
        try {
            new DicePool(\is_int($count) ? $count : DicePool::MIN_COUNT, $sides, $successAt);
        } catch (InvalidDicePool $invalid) {
            throw new \InvalidArgumentException($invalid->getMessage(), $invalid->getCode(), previous: $invalid);
        }
    }

    /**
     * How many dice a roll with this count and situational modifier rolls: their sum kept in 1..100.
     */
    public static function sizeFor(int $count, int $situational): int
    {
        // A formula can give any integer, so bound it first: the sum then stays an integer.
        $bounded = max(-Check::MAX_SITUATIONAL - DicePool::MAX_COUNT, min(DicePool::MAX_COUNT + Check::MAX_SITUATIONAL, $count));

        return max(DicePool::MIN_COUNT, min(DicePool::MAX_COUNT, $bounded + $situational));
    }
}
