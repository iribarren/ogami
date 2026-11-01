<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * The challenge dice of a check (ADR 0049): `count` dice with `sides` faces, each compared on its
 * own against a score. It is not dice notation and not a pool: the total is how many dice the score beats.
 */
final readonly class ChallengeDice
{
    public const int MIN_COUNT = 2;
    public const int MAX_COUNT = 10;
    public const int MIN_SIDES = 2;
    public const int MAX_SIDES = 1000;

    /**
     * @throws InvalidChallengeDice when the count or the sides are out of bounds
     */
    public function __construct(
        public int $count,
        public int $sides,
    ) {
        if ($count < self::MIN_COUNT || $count > self::MAX_COUNT) {
            throw InvalidChallengeDice::count($count, self::MIN_COUNT, self::MAX_COUNT);
        }

        if ($sides < self::MIN_SIDES || $sides > self::MAX_SIDES) {
            throw InvalidChallengeDice::sides($sides, self::MIN_SIDES, self::MAX_SIDES);
        }
    }

    public function roll(RandomNumberGenerator $random): ChallengeRoll
    {
        $faces = [];
        for ($index = 0; $index < $this->count; ++$index) {
            $faces[] = $random->between(1, $this->sides);
        }

        return new ChallengeRoll($this, $faces);
    }
}
