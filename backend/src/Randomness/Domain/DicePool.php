<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * A pool of identical dice counted for successes (ADR 0041): `count` dice with `sides` faces, each
 * succeeding on a face of `successAt` or more. It is not dice notation, which stays as it is.
 */
final readonly class DicePool
{
    public const int MIN_COUNT = 1;
    public const int MAX_COUNT = 100;
    public const int MIN_SIDES = 2;
    public const int MAX_SIDES = 1000;

    /**
     * @throws InvalidDicePool when the count, the sides or the threshold is out of bounds
     */
    public function __construct(
        public int $count,
        public int $sides,
        public int $successAt,
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
    }

    public function roll(RandomNumberGenerator $random): PoolRoll
    {
        $dice = [];
        for ($index = 0; $index < $this->count; ++$index) {
            $dice[] = new PoolDie($face = $random->between(1, $this->sides), $face >= $this->successAt);
        }

        return new PoolRoll($this, $dice);
    }
}
