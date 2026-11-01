<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\GameSystem\Check\ChallengeResult;
use App\Play\Domain\GameSystem\Check\CheckChallenge;
use App\Randomness\Domain\ChallengeDice;
use App\Randomness\Domain\ChallengeRoll;
use App\Randomness\Domain\InvalidChallengeDice;

/**
 * The challenge part of a check entry (ADR 0049): the challenge dice with the faces they showed and
 * whether the score beat each, the score (capped), the cap, the momentum value that was read and
 * whether it cancelled the action die. A match is not stored: it is derived from the faces. Entries
 * of other checks, and those stored before challenge dice, have no challenge part.
 *
 * @phpstan-type ChallengeDieData array{face: int, beaten: bool}
 * @phpstan-type ChallengeData array{sides: int, scoreMax: ?int, score: int, momentum: ?int, cancelled: bool, dice: list<ChallengeDieData>}
 */
final readonly class CheckChallengeContent
{
    /**
     * @param list<ChallengeDieData> $dice in roll order
     */
    private function __construct(
        public int $sides,
        public ?int $scoreMax,
        public int $score,
        public ?int $momentum,
        public bool $cancelled,
        public array $dice,
    ) {
    }

    public static function fromResult(CheckChallenge $challenge, ChallengeResult $result): self
    {
        return self::of(
            $challenge->sides,
            $challenge->scoreMax,
            $result->score,
            $result->momentum,
            $result->cancelled,
            array_map(static fn (int $face): array => ['face' => $face, 'beaten' => $face < $result->score], $result->roll->faces()),
        );
    }

    /**
     * @param array<mixed> $data the stored challenge part
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            ContentData::int($data, 'sides'),
            ContentData::nullableInt($data, 'scoreMax'),
            ContentData::int($data, 'score'),
            ContentData::nullableInt($data, 'momentum'),
            ContentData::bool($data, 'cancelled'),
            array_map(static fn (array $die): array => [
                'face' => ContentData::int($die, 'face'),
                'beaten' => ContentData::bool($die, 'beaten'),
            ], ContentData::listOfArrays($data, 'dice')),
        );
    }

    /**
     * @param list<ChallengeDieData> $dice
     *
     * @throws InvalidJournalEntryContent when the part contradicts itself: the sides or the number of dice are out of
     *                                    bounds, a face is outside its sides, a die's beaten flag disagrees with its face and the score,
     *                                    the cap is out of 1..1000 or below the score, or the die is cancelled without a negative momentum
     */
    private static function of(int $sides, ?int $scoreMax, int $score, ?int $momentum, bool $cancelled, array $dice): self
    {
        if ($sides < ChallengeDice::MIN_SIDES || $sides > ChallengeDice::MAX_SIDES) {
            throw InvalidJournalEntryContent::malformed('sides', \sprintf('between %d and %d', ChallengeDice::MIN_SIDES, ChallengeDice::MAX_SIDES));
        }

        if (\count($dice) < ChallengeDice::MIN_COUNT || \count($dice) > ChallengeDice::MAX_COUNT) {
            throw InvalidJournalEntryContent::malformed('dice', \sprintf('%d to %d challenge dice', ChallengeDice::MIN_COUNT, ChallengeDice::MAX_COUNT));
        }

        try {
            ChallengeRoll::ofFaces(new ChallengeDice(\count($dice), $sides), array_column($dice, 'face'));
        } catch (InvalidChallengeDice) {
            throw InvalidJournalEntryContent::malformed('face', 'between 1 and the sides');
        }

        foreach ($dice as $die) {
            if ($die['face'] < $score !== $die['beaten']) {
                throw InvalidJournalEntryContent::malformed('beaten', 'whether the face is below the score');
            }
        }

        if (null !== $scoreMax && ($scoreMax < CheckChallenge::MIN_SCORE_MAX || $scoreMax > CheckChallenge::MAX_SCORE_MAX)) {
            throw InvalidJournalEntryContent::malformed('scoreMax', \sprintf('between %d and %d', CheckChallenge::MIN_SCORE_MAX, CheckChallenge::MAX_SCORE_MAX));
        }

        if (null !== $scoreMax && $score > $scoreMax) {
            throw InvalidJournalEntryContent::malformed('score', 'at most the score cap');
        }

        if ($cancelled && (null === $momentum || $momentum >= 0)) {
            throw InvalidJournalEntryContent::malformed('cancelled', 'false unless the momentum is below 0');
        }

        return new self($sides, $scoreMax, $score, $momentum, $cancelled, $dice);
    }

    /**
     * How many challenge dice the score beat: the total of the check.
     */
    public function beaten(): int
    {
        return \count(array_filter($this->dice, static fn (array $die): bool => $die['beaten']));
    }

    /**
     * Whether every die shows the same face (ADR 0049).
     */
    public function isMatch(): bool
    {
        return 1 === \count(array_unique(array_column($this->dice, 'face')));
    }

    /**
     * @return ChallengeData
     */
    public function toArray(): array
    {
        return [
            'sides' => $this->sides,
            'scoreMax' => $this->scoreMax,
            'score' => $this->score,
            'momentum' => $this->momentum,
            'cancelled' => $this->cancelled,
            'dice' => $this->dice,
        ];
    }
}
