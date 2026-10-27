<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * A pool of identical dice counted for successes (ADR 0041): `count` dice with `sides` faces, each
 * succeeding on a face of `successAt` or more. It is not dice notation, which stays as it is.
 *
 * ADR 0044 adds Hunger dice, taken out of the pool and rolled after the regular ones (the Hunger
 * count is clamped to 0..count), and a bonus for every pair of dice showing the top face.
 */
final readonly class DicePool
{
    public const int MIN_COUNT = 1;
    public const int MAX_COUNT = 100;
    public const int MIN_SIDES = 2;
    public const int MAX_SIDES = 1000;

    /** How many of the dice are Hunger dice, never more than the pool holds. */
    public int $hunger;

    /**
     * @param int $hunger         how many of the dice are Hunger dice, clamped to 0..count
     * @param int $criticalsBonus the successes every critical pair adds, 0 for no critical pairs
     *
     * @throws InvalidDicePool when the count, the sides, the threshold or the bonus is out of bounds
     */
    public function __construct(
        public int $count,
        public int $sides,
        public int $successAt,
        int $hunger = 0,
        public int $criticalsBonus = 0,
    ) {
        if ($count < self::MIN_COUNT || $count > self::MAX_COUNT) {
            throw InvalidDicePool::count($count, self::MIN_COUNT, self::MAX_COUNT);
        }

        if ($sides < self::MIN_SIDES || $sides > self::MAX_SIDES) {
            throw InvalidDicePool::sides($sides, self::MIN_SIDES, self::MAX_SIDES);
        }

        if ($successAt < 1 || $successAt > $sides) {
            throw InvalidDicePool::successAt($successAt, $sides);
        }

        if ($criticalsBonus < 0) {
            throw InvalidDicePool::criticalsBonus($criticalsBonus);
        }

        $this->hunger = max(0, min($hunger, $count));
    }

    public function roll(RandomNumberGenerator $random): PoolRoll
    {
        $dice = [];
        for ($index = 0; $index < $this->count; ++$index) {
            $dice[] = $this->die($index, $random->between(1, $this->sides));
        }

        return new PoolRoll($this, $dice);
    }

    /**
     * The die at a position of the roll order: regular dice first, then the Hunger dice.
     */
    public function die(int $index, int $face, bool $rerolled = false): PoolDie
    {
        $group = $index >= $this->count - $this->hunger ? DieGroup::Hunger : DieGroup::Pool;

        return new PoolDie($face, $face >= $this->successAt, $rerolled, $group);
    }
}
