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
 * Burning momentum (ADR 0049) adds the burn part and changes nothing else: the score and each die's
 * beaten flag stay those of the first roll, and the dice the burn score beats are the check's total.
 * Entries stored before burning have no burn part.
 *
 * @phpstan-import-type BurnData from CheckChallengeBurnContent
 *
 * @phpstan-type ChallengeDieData array{face: int, beaten: bool}
 * @phpstan-type ChallengeData array{sides: int, scoreMax: ?int, score: int, momentum: ?int, cancelled: bool, dice: list<ChallengeDieData>, burn?: BurnData}
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
        public ?CheckChallengeBurnContent $burn = null,
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
            null === ($burn = ContentData::optionalArray($data, 'burn')) ? null : CheckChallengeBurnContent::fromArray($burn),
        );
    }

    /**
     * This part once the momentum was burned.
     *
     * @throws CheckAlreadyPushed         when it was burned already
     * @throws InvalidJournalEntryContent when the burn contradicts the roll
     */
    public function burned(CheckChallengeBurnContent $burn): self
    {
        return $this->burn instanceof CheckChallengeBurnContent ? throw CheckAlreadyPushed::roll() : self::of($this->sides, $this->scoreMax, $this->score, $this->momentum, $this->cancelled, $this->dice, $burn);
    }

    /**
     * @param list<ChallengeDieData> $dice
     *
     * @throws InvalidJournalEntryContent when the part contradicts itself: the sides or the number of dice are out of
     *                                    bounds, a face is outside its sides, a die's beaten flag disagrees with its face and the score,
     *                                    the cap is out of 1..1000 or below the score, or the die is cancelled without a negative momentum,
     *                                    or a burn has no momentum on the roll, a score that is not its momentum at most the cap, or beats no more dice than the score
     */
    private static function of(int $sides, ?int $scoreMax, int $score, ?int $momentum, bool $cancelled, array $dice, ?CheckChallengeBurnContent $burn = null): self
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

        if ($burn instanceof CheckChallengeBurnContent) {
            if (null === $momentum) {
                throw InvalidJournalEntryContent::malformed('burn', 'absent: the roll has no momentum to burn');
            }

            if ($burn->score !== (null === $scoreMax ? $burn->momentum : min($burn->momentum, $scoreMax))) {
                throw InvalidJournalEntryContent::malformed('burn', 'a score that is the momentum burned, at most the score cap');
            }

            if (self::beatenBy($dice, $burn->score) <= self::beatenBy($dice, $score)) {
                throw InvalidJournalEntryContent::malformed('burn', 'a score that beats more dice than the score of the roll');
            }
        }

        return new self($sides, $scoreMax, $score, $momentum, $cancelled, $dice, $burn);
    }

    /**
     * @param list<ChallengeDieData> $dice
     */
    private static function beatenBy(array $dice, int $score): int
    {
        return \count(array_filter($dice, static fn (array $die): bool => $die['face'] < $score));
    }

    /**
     * How many challenge dice the score of the first roll beat.
     */
    public function beaten(): int
    {
        return \count(array_filter($this->dice, static fn (array $die): bool => $die['beaten']));
    }

    /**
     * How many challenge dice the roll beats as it stands, after a burn: the total of the check.
     */
    public function total(): int
    {
        return $this->burn instanceof CheckChallengeBurnContent ? self::beatenBy($this->dice, $this->burn->score) : $this->beaten();
    }

    /**
     * The challenge dice as they were rolled.
     */
    public function roll(): ChallengeRoll
    {
        return ChallengeRoll::ofFaces(new ChallengeDice(\count($this->dice), $this->sides), array_column($this->dice, 'face'));
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
        ] + ($this->burn instanceof CheckChallengeBurnContent ? ['burn' => $this->burn->toArray()] : []);
    }
}
