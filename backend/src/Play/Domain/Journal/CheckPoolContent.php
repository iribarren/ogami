<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\ModifierTerm;
use App\Play\Domain\GameSystem\Check\PoolResult;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Check\SpecialResult;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\DieGroup;
use App\Randomness\Domain\PoolDie;
use App\Randomness\Domain\PoolRoll;

/**
 * The pool part of a check entry (ADR 0041): the count the release gave (a number, or what its
 * formula read from the sheet, with those terms), the size actually rolled once the situational
 * modifier was added, the dice and their successes. Entries of dice checks and entries stored before
 * pools have no pool part. A pushed roll also has a push part (CheckPushContent) and keeps the dice
 * and successes of the first roll beside it.
 *
 * ADR 0044: a die's group is `pool` or `hunger` (Hunger dice come last), `criticalsBonus` is what a critical
 * pair adds to the total and `special` the special result of the roll. Entries stored before it have only
 * `pool` dice, no bonus and no special, and read as a bonus of 0 and no special result.
 *
 * @phpstan-import-type PushData from CheckPushContent
 *
 * @phpstan-type PoolTermData array{reference: string, label: string, value: int}
 * @phpstan-type PoolDieData array{group: string, face: int, success: bool}
 * @phpstan-type PoolData array{count: int, countFormula: ?string, terms: list<PoolTermData>, size: int, sides: int, successAt: int, dice: list<PoolDieData>, successes: int, criticalsBonus?: int, special?: string, push?: PushData}
 */
final readonly class CheckPoolContent
{
    public const int MAX_CRITICALS_BONUS = 10;

    /**
     * @param ?string            $countFormula null when the count is a number
     * @param list<PoolTermData> $terms        what each reference of the count formula read; empty without a formula
     * @param list<PoolDieData>  $dice         in roll order, as first rolled
     * @param ?string            $special      the special result of the first roll, null for none
     * @param ?CheckPushContent  $push         null until the roll is pushed
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
        public int $criticalsBonus = 0,
        public ?string $special = null,
        public ?CheckPushContent $push = null,
    ) {
    }

    public static function fromResult(CheckPool $pool, PoolResult $result, int $situational, ReferenceLabels $labels, ?SpecialResult $special): self
    {
        return self::of(
            $result->count,
            $pool->count instanceof Formula ? $pool->count->toString() : null,
            array_map(static fn (ModifierTerm $term): array => ['reference' => $term->token(), 'label' => $labels->label($term->reference), 'value' => $term->value], $result->terms),
            $result->roll->size(),
            $pool->sides,
            $pool->successAt,
            array_map(static fn (PoolDie $die): array => ['group' => $die->group()->value, 'face' => $die->face(), 'success' => $die->isSuccess()], $result->roll->dice()),
            $result->roll->successes(),
            $situational,
            $result->roll->pool()->criticalsBonus,
            $special?->value,
        );
    }

    /**
     * @param array<mixed> $data the stored pool part
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data, int $situational): self
    {
        $push = ContentData::optionalArray($data, 'push');

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
            ContentData::optionalInt($data, 'criticalsBonus', 0),
            ContentData::optionalString($data, 'special'),
            null === $push ? null : CheckPushContent::fromArray($push),
        );
    }

    /**
     * This pool once its roll was pushed: the first roll stays as it was and the push is kept beside it.
     * The push is held to the same rules as a stored one.
     *
     * @throws CheckAlreadyPushed         when the roll was pushed already
     * @throws InvalidJournalEntryContent when the push contradicts the first roll
     */
    public function pushed(CheckPushContent $push, int $situational): self
    {
        if ($this->push instanceof CheckPushContent) {
            throw CheckAlreadyPushed::roll();
        }

        return self::of($this->count, $this->countFormula, $this->terms, $this->size, $this->sides, $this->successAt, $this->dice, $this->successes, $situational, $this->criticalsBonus, $this->special, $push);
    }

    /**
     * @param list<PoolTermData> $terms
     * @param list<PoolDieData>  $dice
     *
     * @throws InvalidJournalEntryContent when the part contradicts itself: the size is not the count plus the situational
     *                                    modifier in 1..100, a die is out of its sides or its success disagrees with its face,
     *                                    or the successes are not those of the dice, a Hunger die comes before a regular one, the critical bonus is out of
     *                                    0..10, the special result is unknown or contradicts the dice, or the push does not follow from the first roll
     */
    private static function of(int $count, ?string $countFormula, array $terms, int $size, int $sides, int $successAt, array $dice, int $successes, int $situational, int $criticalsBonus = 0, ?string $special = null, ?CheckPushContent $push = null): self
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

        if ($criticalsBonus < 0 || $criticalsBonus > self::MAX_CRITICALS_BONUS) {
            throw InvalidJournalEntryContent::malformed('criticalsBonus', \sprintf('between 0 and %d', self::MAX_CRITICALS_BONUS));
        }

        self::assertGroups($dice, 'group');
        foreach ($dice as $die) {
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

        self::assertSpecial($special, self::rollOf($dice, $sides, $successAt, $criticalsBonus), 'special');
        if ($push instanceof CheckPushContent) {
            self::assertPushFollows($push, $dice, $sides, $successAt, $criticalsBonus);
        }

        return new self($count, $countFormula, $terms, $size, $sides, $successAt, $dice, $successes, $criticalsBonus, $special, $push);
    }

    /**
     * The roll the dice show, rebuilt through the kernel so the total and the pairs are the kernel's.
     *
     * @param list<array{group: string, face: int}> $dice already checked: groups known, Hunger dice last, faces within the sides
     */
    private static function rollOf(array $dice, int $sides, int $successAt, int $criticalsBonus): PoolRoll
    {
        $hunger = \count(array_filter($dice, static fn (array $die): bool => DieGroup::Hunger->value === $die['group']));

        return PoolRoll::ofFaces(new DicePool(\count($dice), $sides, $successAt, $hunger, $criticalsBonus), array_map(static fn (array $die): int => $die['face'], $dice));
    }

    /**
     * @param list<array{group: string}> $dice
     *
     * @throws InvalidJournalEntryContent when a group is unknown or a regular die follows a Hunger die
     */
    private static function assertGroups(array $dice, string $field): void
    {
        $hunger = false;
        foreach ($dice as $die) {
            $group = DieGroup::tryFrom($die['group']) ?? throw InvalidJournalEntryContent::malformed($field, '"pool" or "hunger"');
            if (DieGroup::Pool === $group && $hunger) {
                throw InvalidJournalEntryContent::malformed($field, 'Hunger dice after the regular dice');
            }

            $hunger = $hunger || DieGroup::Hunger === $group;
        }
    }

    /**
     * A special result needs the dice it comes from (ADR 0044); whether the roll failed is Check's to say.
     *
     * @throws InvalidJournalEntryContent when the special result is unknown or the dice cannot show it
     */
    private static function assertSpecial(?string $special, PoolRoll $roll, string $field): void
    {
        if (null === $special) {
            return;
        }

        $result = SpecialResult::tryFrom($special) ?? throw InvalidJournalEntryContent::malformed($field, 'critical, messyCritical, bestialFailure or null');
        $possible = match ($result) {
            SpecialResult::Critical => $roll->hasCriticalPair(),
            SpecialResult::MessyCritical => $roll->hasHungerCriticalPair(),
            SpecialResult::BestialFailure => $roll->hasHungerOne(),
        };
        if (!$possible) {
            throw InvalidJournalEntryContent::malformed($field, \sprintf('a special result the dice can show, not "%s"', $special));
        }
    }

    /**
     * The total of the roll as it stands: the first roll, or the pushed one.
     */
    public function total(): int
    {
        return self::rollOf($this->push instanceof CheckPushContent ? $this->push->dice : $this->dice, $this->sides, $this->successAt, $this->criticalsBonus)->total();
    }

    /**
     * The special result of the roll as it stands: after a push, the push's, even when it is none.
     */
    public function currentSpecial(): ?string
    {
        return $this->push instanceof CheckPushContent ? $this->push->special : $this->special;
    }

    /**
     * The first roll as a kernel roll, e.g. to know whether it can be pushed.
     */
    public function roll(): PoolRoll
    {
        return self::rollOf($this->dice, $this->sides, $this->successAt, $this->criticalsBonus);
    }

    /**
     * A push re-rolls the regular dice that failed, once, and keeps the others (ADR 0044): the same dice in
     * the same groups, a die that succeeded or a Hunger die keeps its face and is not re-rolled, a failed
     * regular die that was re-rolled says so, the re-rolled ones are the first failed regular dice in roll
     * order (a cap leaves the later ones as they were), and at least one die was re-rolled.
     *
     * @param list<PoolDieData> $dice the first roll
     */
    private static function assertPushFollows(CheckPushContent $push, array $dice, int $sides, int $successAt, int $criticalsBonus): void
    {
        if (\count($push->dice) !== \count($dice)) {
            throw InvalidJournalEntryContent::malformed('push.dice', 'as many dice as the pool rolled');
        }

        self::assertGroups($push->dice, 'push.group');
        $rerolled = 0;
        $kept = false;
        foreach ($push->dice as $index => $after) {
            $before = $dice[$index];
            if ($after['group'] !== $before['group']) {
                throw InvalidJournalEntryContent::malformed('push.group', 'the group of the die before the push');
            }

            if ($after['face'] < 1 || $after['face'] > $sides) {
                throw InvalidJournalEntryContent::malformed('push.face', 'between 1 and the sides');
            }

            if ($after['face'] >= $successAt !== $after['success']) {
                throw InvalidJournalEntryContent::malformed('push.success', 'whether the face reaches the success threshold');
            }

            $mayReroll = !$before['success'] && DieGroup::Pool->value === $before['group'];
            if ($after['rerolled'] && !$mayReroll) {
                throw InvalidJournalEntryContent::malformed('push.rerolled', 'false for a die that succeeded or a Hunger die, which a push keeps');
            }

            if (!$after['rerolled'] && $after['face'] !== $before['face']) {
                throw InvalidJournalEntryContent::malformed('push.face', 'the face of a die the push did not re-roll');
            }

            if ($mayReroll && $after['rerolled'] && $kept) {
                throw InvalidJournalEntryContent::malformed('push.rerolled', 'the first failed regular dice in roll order');
            }

            $kept = $kept || $mayReroll && !$after['rerolled'];
            $rerolled += $after['rerolled'] ? 1 : 0;
        }

        if (0 === $rerolled) {
            throw InvalidJournalEntryContent::malformed('push', 'a push that re-rolled at least one die');
        }

        self::assertSpecial($push->special, self::rollOf($push->dice, $sides, $successAt, $criticalsBonus), 'push.special');
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
        ] + (0 !== $this->criticalsBonus ? ['criticalsBonus' => $this->criticalsBonus] : [])
          + (null !== $this->special ? ['special' => $this->special] : [])
          + ($this->push instanceof CheckPushContent ? ['push' => $this->push->toArray()] : []);
    }
}
