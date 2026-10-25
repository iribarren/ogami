<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\ModifierTerm;
use App\Play\Domain\GameSystem\Check\PoolResult;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\PoolDie;

/**
 * The pool part of a check entry (ADR 0041): the count the release gave (a number, or what its
 * formula read from the sheet, with those terms), the size actually rolled once the situational
 * modifier was added, the dice and their successes. Entries of dice checks and entries stored before
 * pools have no pool part.
 *
 * @phpstan-type PoolTermData array{reference: string, label: string, value: int}
 * @phpstan-type PoolDieData array{group: string, face: int, success: bool}
 * @phpstan-type PoolData array{count: int, countFormula: ?string, terms: list<PoolTermData>, size: int, sides: int, successAt: int, dice: list<PoolDieData>, successes: int}
 */
final readonly class CheckPoolContent
{
    /** The group every die of a pool is journalled in, room for the groups of later rules. */
    public const string GROUP = 'pool';

    /**
     * @param ?string            $countFormula null when the count is a number
     * @param list<PoolTermData> $terms        what each reference of the count formula read; empty without a formula
     * @param list<PoolDieData>  $dice         in roll order
     */
    private function __construct(
        public int $count,
        public ?string $countFormula,
        public array $terms,
        public int $size,
        public int $sides,
        public int $successAt,
        public array $dice,
        public int $successes,
    ) {
    }

    public static function fromResult(CheckPool $pool, PoolResult $result, int $situational, ReferenceLabels $labels): self
    {
        return self::of(
            $result->count,
            $pool->count instanceof Formula ? $pool->count->toString() : null,
            array_map(static fn (ModifierTerm $term): array => ['reference' => $term->token(), 'label' => $labels->label($term->reference), 'value' => $term->value], $result->terms),
            $result->roll->size(),
            $pool->sides,
            $pool->successAt,
            array_map(static fn (PoolDie $die): array => ['group' => self::GROUP, 'face' => $die->face(), 'success' => $die->isSuccess()], $result->roll->dice()),
            $result->roll->successes(),
            $situational,
        );
    }

    /**
     * @param array<mixed> $data the stored pool part
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data, int $situational): self
    {
        return self::of(
            ContentData::int($data, 'count'),
            ContentData::nullableString($data, 'countFormula'),
            array_map(static fn (array $term): array => [
                'reference' => ContentData::string($term, 'reference'),
                'label' => ContentData::string($term, 'label'),
                'value' => ContentData::int($term, 'value'),
            ], ContentData::listOfArrays($data, 'terms')),
            ContentData::int($data, 'size'),
            ContentData::int($data, 'sides'),
            ContentData::int($data, 'successAt'),
            array_map(static fn (array $die): array => [
                'group' => ContentData::string($die, 'group'),
                'face' => ContentData::int($die, 'face'),
                'success' => ContentData::bool($die, 'success'),
            ], ContentData::listOfArrays($data, 'dice')),
            ContentData::int($data, 'successes'),
            $situational,
        );
    }

    /**
     * @param list<PoolTermData> $terms
     * @param list<PoolDieData>  $dice
     *
     * @throws InvalidJournalEntryContent when the part contradicts itself: the size is not the count plus the situational
     *                                    modifier in 1..100, a die is out of its sides or its success disagrees with its face,
     *                                    or the successes are not those of the dice
     */
    private static function of(int $count, ?string $countFormula, array $terms, int $size, int $sides, int $successAt, array $dice, int $successes, int $situational): self
    {
        if (null !== $countFormula && '' === trim($countFormula)) {
            throw InvalidJournalEntryContent::blank('A pool count formula');
        }

        if (null === $countFormula && [] !== $terms) {
            throw InvalidJournalEntryContent::malformed('terms', 'empty when the pool count is not a formula');
        }

        if ($sides < DicePool::MIN_SIDES || $sides > DicePool::MAX_SIDES) {
            throw InvalidJournalEntryContent::malformed('sides', \sprintf('between %d and %d', DicePool::MIN_SIDES, DicePool::MAX_SIDES));
        }

        if ($successAt < 1 || $successAt > $sides) {
            throw InvalidJournalEntryContent::malformed('successAt', 'between 1 and the sides');
        }

        if (\count($dice) !== $size || $size !== CheckPool::sizeFor($count, $situational)) {
            throw InvalidJournalEntryContent::malformed('size', 'the count plus the situational modifier, kept in 1 to 100, and the number of dice');
        }

        foreach ($dice as $die) {
            if (self::GROUP !== $die['group']) {
                throw InvalidJournalEntryContent::malformed('group', \sprintf('"%s"', self::GROUP));
            }

            if ($die['face'] < 1 || $die['face'] > $sides) {
                throw InvalidJournalEntryContent::malformed('face', 'between 1 and the sides');
            }

            if ($die['face'] >= $successAt !== $die['success']) {
                throw InvalidJournalEntryContent::malformed('success', 'whether the face reaches the success threshold');
            }
        }

        if ($successes !== \count(array_filter($dice, static fn (array $die): bool => $die['success']))) {
            throw InvalidJournalEntryContent::malformed('successes', 'the number of dice that succeeded');
        }

        return new self($count, $countFormula, $terms, $size, $sides, $successAt, $dice, $successes);
    }

    /**
     * @return PoolData
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'countFormula' => $this->countFormula,
            'terms' => $this->terms,
            'size' => $this->size,
            'sides' => $this->sides,
            'successAt' => $this->successAt,
            'dice' => $this->dice,
            'successes' => $this->successes,
        ];
    }
}
