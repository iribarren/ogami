<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\Reference;
use App\Randomness\Domain\ChallengeDice;

/**
 * The challenge dice a saved check rolls beside its action die (ADR 0049), as a test roll reads them:
 * the dice, the cap on the score and the key of the number field whose value is the momentum. Studio
 * cannot import Play, so this mirrors Play's CheckChallenge.
 */
final readonly class CheckTestChallenge
{
    public const int MAX_SCORE = 1000;

    /**
     * @param ?int    $scoreMax the highest the score can be, 1..1000; null for no cap
     * @param ?string $momentum the key of a number field of the sheet; null when the check has none
     *
     * @throws \InvalidArgumentException when the cap is outside 1..1000
     * @throws InvalidFormula            when the momentum is not a field key
     */
    public function __construct(
        public ChallengeDice $dice,
        public ?int $scoreMax = null,
        public ?string $momentum = null,
    ) {
        if (null !== $scoreMax && ($scoreMax < 1 || $scoreMax > self::MAX_SCORE)) {
            throw new \InvalidArgumentException(\sprintf('The score cap is 1 to %d, %d given.', self::MAX_SCORE, $scoreMax));
        }

        if (null !== $momentum) {
            Reference::field($momentum);
        }
    }

    public function momentumReference(): ?Reference
    {
        return null === $this->momentum ? null : Reference::field($this->momentum);
    }

    /**
     * The score a momentum burns the roll to: the momentum, capped.
     */
    public function burnScore(int $momentum): int
    {
        return null === $this->scoreMax ? $momentum : min($momentum, $this->scoreMax);
    }
}
