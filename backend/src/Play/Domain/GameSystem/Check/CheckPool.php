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
 *
 * ADR 0044 adds critical pairs (every pair of top faces adds `criticals` successes) and Hunger dice
 * (as many dice of the pool as `hunger`, a number or a formula over the sheet, clamped to the size).
 */
final readonly class CheckPool
{
    public const int MAX_CRITICALS = 10;

    /**
     * @param int|Formula      $count     a number in 1..100, or a formula over the sheet
     * @param ?int             $criticals the successes a critical pair adds, 1..10; null for no critical pairs
     * @param int|Formula|null $hunger    the Hunger dice, a number in 0..100 or a formula over the sheet; null for none
     *
     * @throws \InvalidArgumentException when the count, the sides, the success threshold, the criticals bonus or the Hunger count is out of bounds
     */
    public function __construct(
        public int|Formula $count,
        public int $sides,
        public int $successAt,
        public ?int $criticals = null,
        public int|Formula|null $hunger = null,
    ) {
        try {
            new DicePool(\is_int($count) ? $count : DicePool::MIN_COUNT, $sides, $successAt);
        } catch (InvalidDicePool $invalid) {
            throw new \InvalidArgumentException($invalid->getMessage(), $invalid->getCode(), previous: $invalid);
        }

        if (null !== $criticals && ($criticals < 1 || $criticals > self::MAX_CRITICALS)) {
            throw new \InvalidArgumentException(\sprintf('The bonus of a critical pair is 1 to %d, %d given.', self::MAX_CRITICALS, $criticals));
        }

        if (\is_int($hunger) && ($hunger < 0 || $hunger > DicePool::MAX_COUNT)) {
            throw new \InvalidArgumentException(\sprintf('The Hunger dice of a pool are 0 to %d, %d given.', DicePool::MAX_COUNT, $hunger));
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
