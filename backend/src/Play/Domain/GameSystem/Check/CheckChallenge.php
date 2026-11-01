<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Reference;
use App\Randomness\Domain\ChallengeDice;
use App\Randomness\Domain\InvalidChallengeDice;

/**
 * The challenge dice a check compares its score against (ADR 0049): how many, their sides, the cap
 * of the score and the number field whose negative value can cancel the action die.
 */
final readonly class CheckChallenge
{
    public const int MIN_SCORE_MAX = 1;
    public const int MAX_SCORE_MAX = 1000;

    /**
     * @param ?int    $scoreMax the cap of the score, 1..1000; null for none
     * @param ?string $momentum the key of a number field, null for none
     *
     * @throws \InvalidArgumentException when the count, the sides, the cap or the momentum key is out of bounds
     */
    public function __construct(
        public int $count,
        public int $sides,
        public ?int $scoreMax = null,
        public ?string $momentum = null,
    ) {
        try {
            new ChallengeDice($count, $sides);
        } catch (InvalidChallengeDice $invalid) {
            throw new \InvalidArgumentException($invalid->getMessage(), $invalid->getCode(), previous: $invalid);
        }

        if (null !== $scoreMax && ($scoreMax < self::MIN_SCORE_MAX || $scoreMax > self::MAX_SCORE_MAX)) {
            throw new \InvalidArgumentException(\sprintf('The score cap of a challenge is %d to %d, %d given.', self::MIN_SCORE_MAX, self::MAX_SCORE_MAX, $scoreMax));
        }

        if (null !== $momentum && 1 !== preg_match('/^'.Reference::KEY_PATTERN.'$/', $momentum)) {
            throw new \InvalidArgumentException(\sprintf('The momentum of a challenge is a field key, "%s" given.', $momentum));
        }
    }

    public function dice(): ChallengeDice
    {
        return new ChallengeDice($this->count, $this->sides);
    }
}
