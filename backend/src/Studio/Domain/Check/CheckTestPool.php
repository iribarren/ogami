<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Formula\Domain\Formula;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\InvalidDicePool;

/**
 * The pool a saved check rolls instead of a dice expression (ADR 0041), as a test roll reads it:
 * the count as a number or a formula over the sample sheet, the sides and the face a die succeeds
 * from. The size rolled is the count plus the situational modifier, kept in 1..100 (Play's
 * CheckPool::sizeFor). It may also declare Hunger dice, a number or a formula like the count, and the
 * bonus of critical pairs (ADR 0044).
 */
final readonly class CheckTestPool
{
    public const int MAX_CRITICALS_BONUS = 10;

    /**
     * @param int|Formula      $count          a number in 1..100, or a formula over the sheet
     * @param int|Formula|null $hunger         the Hunger dice: a number in 0..100, or a formula over the sheet; null for none
     * @param int              $criticalsBonus the successes every critical pair adds, 1..10; 0 for no critical pairs
     *
     * @throws \InvalidArgumentException when the count, the sides or the success threshold is out of bounds
     */
    public function __construct(
        public int|Formula $count,
        public int $sides,
        public int $successAt,
        public int|Formula|null $hunger = null,
        public int $criticalsBonus = 0,
    ) {
        if (\is_int($hunger) && ($hunger < 0 || $hunger > DicePool::MAX_COUNT)) {
            throw new \InvalidArgumentException(\sprintf('The Hunger count must be in 0..%d, %d given.', DicePool::MAX_COUNT, $hunger));
        }

        if ($criticalsBonus < 0 || $criticalsBonus > self::MAX_CRITICALS_BONUS) {
            throw new \InvalidArgumentException(\sprintf('The critical pairs bonus must be in 1..%d, %d given.', self::MAX_CRITICALS_BONUS, $criticalsBonus));
        }

        try {
            new DicePool(\is_int($count) ? $count : DicePool::MIN_COUNT, $sides, $successAt);
        } catch (InvalidDicePool $invalid) {
            throw new \InvalidArgumentException($invalid->getMessage(), $invalid->getCode(), previous: $invalid);
        }
    }

    /**
     * How many dice a roll with this count and situational modifier rolls: their sum kept in 1..100.
     */
    public function sizeFor(int $count, int $situational): int
    {
        // A formula can give any integer, so bound it first: the sum then stays an integer.
        $bounded = max(-CheckTestRoll::MAX_SITUATIONAL - DicePool::MAX_COUNT, min(DicePool::MAX_COUNT + CheckTestRoll::MAX_SITUATIONAL, $count));

        return max(DicePool::MIN_COUNT, min(DicePool::MAX_COUNT, $bounded + $situational));
    }

    /**
     * @param int $hunger how many of the dice are Hunger dice; the pool clamps it to 0..$size, so a negative formula rolls none
     */
    public function pool(int $size, int $hunger = 0): DicePool
    {
        return new DicePool($size, $this->sides, $this->successAt, $hunger, $this->criticalsBonus);
    }
}
