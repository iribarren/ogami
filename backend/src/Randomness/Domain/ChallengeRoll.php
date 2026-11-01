<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * The result of rolling the challenge dice: the face of every die in roll order. The facts about the
 * dice live here; what a score does with them (ADR 0049) is `beatenBy()`.
 */
final readonly class ChallengeRoll
{
    /**
     * @param list<int> $faces in roll order, one per die
     */
    public function __construct(
        private ChallengeDice $dice,
        private array $faces,
    ) {
    }

    /**
     * Rebuilds a roll from the faces it showed, e.g. a stored one.
     *
     * @param array<int, int> $faces in roll order, one per die; the keys are ignored (stored data is not trusted to be a list)
     *
     * @throws InvalidChallengeDice when there are not as many faces as dice, or a face is outside 1..sides
     */
    public static function ofFaces(ChallengeDice $dice, array $faces): self
    {
        if (\count($faces) !== $dice->count) {
            throw InvalidChallengeDice::dice($dice->count, \count($faces));
        }

        foreach ($faces as $face) {
            if ($face < 1 || $face > $dice->sides) {
                throw InvalidChallengeDice::face($face, $dice->sides);
            }
        }

        return new self($dice, array_values($faces));
    }

    public function dice(): ChallengeDice
    {
        return $this->dice;
    }

    /**
     * @return list<int>
     */
    public function faces(): array
    {
        return $this->faces;
    }

    /**
     * How many dice show a face strictly below the score: a tie goes to the dice.
     */
    public function beatenBy(int $score): int
    {
        return \count(array_filter($this->faces, static fn (int $face): bool => $face < $score));
    }

    /**
     * Whether every die shows the same face.
     */
    public function isMatch(): bool
    {
        return 1 === \count(array_unique($this->faces));
    }
}
