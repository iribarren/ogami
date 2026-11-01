<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\GameSystem\Check\BurnResult;
use App\Play\Domain\GameSystem\Check\ChallengeResult;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckChallenge;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\CheckResult;
use App\Play\Domain\GameSystem\Check\ModifierTerm;
use App\Play\Domain\GameSystem\Check\PoolResult;
use App\Play\Domain\GameSystem\Check\PushResult;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Check\SpecialResult;

/**
 * A Check of the pinned release performed for a Character (ADR 0024): the Character and the check
 * as they were named then, the dice rolled, the modifier read from the sheet, the situational
 * modifier, the Check difficulty that applied, the total and the Outcome band it fell in.
 *
 * The content also keeps the check's hint and, for a check with a modifier, the formula and what each
 * reference of it read, so the card explains the modifier as it was then; entries stored before
 * these were recorded read with no hint, no formula and no terms.
 *
 * A pool check (ADR 0041) has a pool part instead of dice groups and a modifier: its dice with their
 * successes, the count and the size rolled; the dice total and the total are its successes. Entries
 * of dice checks, those stored before pools included, have no pool part and store the same array.
 *
 * A challenge check (ADR 0049) has a challenge part: its dice, the score they were compared against and
 * the total is the number of dice beaten. An action roll keeps its action die as a dice check does; a
 * progress roll has no action die, so its expression is the challenge dice (such as "2d10"), with no
 * groups and a dice total of 0. Entries of other checks have no challenge part and store the same array.
 *
 * A pool check can be pushed once (ADR 0041): the push completes this content, the only change a
 * journal entry's content can take. The pool part keeps the first roll and gains the push part; the
 * dice total, the total and the band are those after the push.
 *
 * A challenge check that names a momentum can be burned once (ADR 0049), the same way: the challenge part gains the
 * burn part and keeps the first roll's score and dice. The dice total (the action die) stays; the total, the band
 * key and the band label are those after the burn.
 *
 * @phpstan-import-type DiceGroupData from RollContent
 * @phpstan-import-type PoolData from CheckPoolContent
 * @phpstan-import-type ChallengeData from CheckChallengeContent
 *
 * @phpstan-type ModifierTermData array{reference: string, label: string, value: int}
 */
final readonly class CheckContent implements JournalEntryContent
{
    public const string KIND = 'check';

    /**
     * @param string                 $expression      the normalized notation of the check's dice, e.g. "1d10"
     * @param list<DiceGroupData>    $groups          the dice groups in notation order, as a roll stores them
     * @param int                    $diceTotal       the value of the dice expression, counting kept dice only
     * @param ?int                   $difficulty      null when the check declares no difficulty
     * @param ?string                $hint            the check's hint when it was performed
     * @param ?string                $modifierFormula null when the check has no modifier
     * @param list<ModifierTermData> $modifierTerms   what each reference of the formula read; empty without a formula
     * @param ?CheckPoolContent      $pool            null for a dice check
     * @param ?CheckChallengeContent $challenge       null unless the check rolled challenge dice
     */
    private function __construct(
        private string $characterId,
        private string $characterName,
        private string $checkKey,
        private string $checkLabel,
        private string $expression,
        private array $groups,
        private int $diceTotal,
        private int $modifier,
        private int $situational,
        private ?int $difficulty,
        private int $total,
        private string $bandKey,
        private string $bandLabel,
        private ?string $hint,
        private ?string $modifierFormula,
        private array $modifierTerms,
        private ?CheckPoolContent $pool = null,
        private ?CheckChallengeContent $challenge = null,
    ) {
    }

    /**
     * @throws InvalidJournalEntryContent when the Character id is blank
     */
    public static function fromResult(string $characterId, string $characterName, Check $check, CheckResult $result, ReferenceLabels $labels): self
    {
        if ($check->pool instanceof CheckPool && $result->pool instanceof PoolResult) {
            $pool = CheckPoolContent::fromResult($check->pool, $result->pool, $result->situational, $labels, $result->special);

            return self::of($characterId, $characterName, $check->key, $check->label, \sprintf('%dd%d', $pool->size, $pool->sides), [], $result->total, 0, $result->situational, $result->difficulty, $result->total, $result->band->key, $result->band->label, $check->hint, null, [], $pool);
        }

        if ($check->challenge instanceof CheckChallenge && $result->challenge instanceof ChallengeResult) {
            return self::ofChallenge($characterId, $characterName, $check, $check->challenge, $result, $labels);
        }

        $roll = $result->roll ?? throw new \LogicException(\sprintf('The result of dice check "%s" has no roll.', $check->key));

        return self::of(
            $characterId,
            $characterName,
            $check->key,
            $check->label,
            $roll->expression()->notation(),
            RollContent::groupsOf($roll),
            $roll->total(),
            $result->modifier,
            $result->situational,
            $result->difficulty,
            $result->total,
            $result->band->key,
            $result->band->label,
            $check->hint,
            $check->modifier?->toString(),
            self::termsOf($result, $labels),
        );
    }

    /**
     * A challenge roll (ADR 0049): an action roll keeps its action die, a progress roll has none and
     * stores the challenge dice as its expression.
     */
    private static function ofChallenge(string $characterId, string $characterName, Check $check, CheckChallenge $challenge, CheckResult $result, ReferenceLabels $labels): self
    {
        $roll = $result->roll;

        return self::of(
            $characterId,
            $characterName,
            $check->key,
            $check->label,
            $roll?->expression()->notation() ?? \sprintf('%dd%d', $challenge->count, $challenge->sides),
            $roll instanceof \App\Randomness\Domain\Roll ? RollContent::groupsOf($roll) : [],
            $roll?->total() ?? 0,
            $result->modifier,
            $result->situational,
            null,
            $result->total,
            $result->band->key,
            $result->band->label,
            $check->hint,
            $check->modifier?->toString(),
            self::termsOf($result, $labels),
            null,
            CheckChallengeContent::fromResult($challenge, $result->challenge ?? throw new \LogicException('A challenge result is missing.')),
        );
    }

    /**
     * @return list<ModifierTermData>
     */
    private static function termsOf(CheckResult $result, ReferenceLabels $labels): array
    {
        return array_map(static fn (ModifierTerm $term): array => ['reference' => $term->token(), 'label' => $labels->label($term->reference), 'value' => $term->value], $result->modifierTerms);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        $situational = ContentData::int($data, 'situational');
        $pool = ContentData::optionalArray($data, 'pool');
        $challenge = ContentData::optionalArray($data, 'challenge');

        return self::of(
            ContentData::string($data, 'characterId'),
            ContentData::string($data, 'characterName'),
            ContentData::string($data, 'checkKey'),
            ContentData::string($data, 'checkLabel'),
            ContentData::string($data, 'expression'),
            RollContent::groupsFromArray($data),
            ContentData::int($data, 'diceTotal'),
            ContentData::int($data, 'modifier'),
            $situational,
            ContentData::nullableInt($data, 'difficulty'),
            ContentData::int($data, 'total'),
            ContentData::string($data, 'bandKey'),
            ContentData::string($data, 'bandLabel'),
            ContentData::optionalString($data, 'hint'),
            ContentData::optionalString($data, 'modifierFormula'),
            self::termsFromArray($data),
            null === $pool ? null : CheckPoolContent::fromArray($pool, $situational),
            null === $challenge ? null : CheckChallengeContent::fromArray($challenge),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<ModifierTermData>
     *
     * @throws InvalidJournalEntryContent
     */
    private static function termsFromArray(array $data): array
    {
        return array_map(static fn (array $term): array => [
            'reference' => ContentData::string($term, 'reference'),
            'label' => ContentData::string($term, 'label'),
            'value' => ContentData::int($term, 'value'),
        ], ContentData::optionalListOfArrays($data, 'modifierTerms'));
    }

    /**
     * @param list<DiceGroupData>    $groups
     * @param list<ModifierTermData> $modifierTerms
     *
     * @throws InvalidJournalEntryContent when an id, key or formula is blank, there are terms without a formula, the total is not the dice plus the modifiers,
     *                                    or a pool check has dice groups or a modifier, an expression that is not its size and sides, or a total that is not its successes plus the bonus of its critical pairs,
     *                                    or a challenge check has a pool or a difficulty, a total that is not the dice beaten, or a score, a cancelled die or a progress roll that contradicts the dice and the modifiers
     */
    private static function of(
        string $characterId,
        string $characterName,
        string $checkKey,
        string $checkLabel,
        string $expression,
        array $groups,
        int $diceTotal,
        int $modifier,
        int $situational,
        ?int $difficulty,
        int $total,
        string $bandKey,
        string $bandLabel,
        ?string $hint,
        ?string $modifierFormula,
        array $modifierTerms,
        ?CheckPoolContent $pool = null,
        ?CheckChallengeContent $challenge = null,
    ): self {
        foreach (['A Character id' => $characterId, 'A check key' => $checkKey, 'A band key' => $bandKey] as $what => $value) {
            if ('' === trim($value)) {
                throw InvalidJournalEntryContent::blank($what);
            }
        }

        if (null !== $modifierFormula && '' === trim($modifierFormula)) {
            throw InvalidJournalEntryContent::blank('A modifier formula');
        }

        if (null === $modifierFormula && [] !== $modifierTerms) {
            throw InvalidJournalEntryContent::malformed('modifierTerms', 'empty when the check has no modifier formula');
        }

        if ($pool instanceof CheckPoolContent) {
            if ([] !== $groups || 0 !== $modifier || null !== $modifierFormula) {
                throw InvalidJournalEntryContent::malformed('pool', 'alone: a pool check has no dice groups and no modifier');
            }

            if (\sprintf('%dd%d', $pool->size, $pool->sides) !== $expression) {
                throw InvalidJournalEntryContent::malformed('expression', 'the size and sides of the pool, such as "3d6"');
            }

            $poolTotal = $pool->total();
            if ($diceTotal !== $poolTotal || $total !== $poolTotal) {
                throw InvalidJournalEntryContent::malformed('total', $pool->push instanceof CheckPushContent ? 'the successes of the pool after the push, plus the bonus of its critical pairs' : 'the successes of the pool, plus the bonus of its critical pairs');
            }
        }

        if ($challenge instanceof CheckChallengeContent) {
            self::assertChallenge($challenge, $pool, $expression, $groups, $diceTotal, $modifier, $situational, $difficulty, $total);
        }

        // A sum that overflows the integers is a float, and never equals the total.
        if (!$pool instanceof CheckPoolContent && !$challenge instanceof CheckChallengeContent && $diceTotal + $modifier + $situational !== $total) {
            throw InvalidJournalEntryContent::malformed('total', 'the dice total plus the modifier and the situational modifier');
        }

        return new self($characterId, $characterName, $checkKey, $checkLabel, $expression, $groups, $diceTotal, $modifier, $situational, $difficulty, $total, $bandKey, $bandLabel, $hint, $modifierFormula, $modifierTerms, $pool, $challenge);
    }

    /**
     * What a challenge part says about the rest of the entry (ADR 0049). A progress roll is the one with no
     * dice groups and the challenge dice as its expression.
     *
     * @param list<DiceGroupData> $groups
     *
     * @throws InvalidJournalEntryContent
     */
    private static function assertChallenge(CheckChallengeContent $challenge, ?CheckPoolContent $pool, string $expression, array $groups, int $diceTotal, int $modifier, int $situational, ?int $difficulty, int $total): void
    {
        if ($pool instanceof CheckPoolContent) {
            throw InvalidJournalEntryContent::malformed('pool', 'absent: a challenge check rolls no pool');
        }

        if (null !== $difficulty) {
            throw InvalidJournalEntryContent::malformed('difficulty', 'null: a challenge check has no difficulty');
        }

        if ($total !== $challenge->total()) {
            throw InvalidJournalEntryContent::malformed('total', $challenge->burn instanceof CheckChallengeBurnContent ? 'the number of challenge dice the burned score beat' : 'the number of challenge dice the score beat');
        }

        $progress = [] === $groups && \sprintf('%dd%d', \count($challenge->dice), $challenge->sides) === $expression;
        if ($progress && (0 !== $diceTotal || null !== $challenge->momentum)) {
            throw InvalidJournalEntryContent::malformed(null !== $challenge->momentum ? 'momentum' : 'diceTotal', 'absent: a progress roll has no action die');
        }

        $cancelled = null !== $challenge->momentum && $challenge->momentum < 0 && -$challenge->momentum === $diceTotal;
        if (!$progress && $challenge->cancelled !== $cancelled) {
            throw InvalidJournalEntryContent::malformed('cancelled', 'true when a negative momentum equals the action die, else false');
        }

        // A sum that overflows the integers is a float, and never equals the score.
        $sum = ($challenge->cancelled ? 0 : $diceTotal) + $modifier + $situational;
        if ($challenge->score !== (null === $challenge->scoreMax ? $sum : min($challenge->scoreMax, $sum))) {
            throw InvalidJournalEntryContent::malformed('score', 'the action die (0 when cancelled) plus the modifier and the situational modifier, at most the score cap');
        }
    }

    /**
     * This check once its pool roll was pushed (ADR 0041): the push part is kept beside the first
     * roll, and the totals and the band are those after the push. The cost's sheet changes are the
     * ones the push applied to the Character.
     *
     * @param list<SheetChange> $changes
     *
     * @throws CheckAlreadyPushed         when the roll was pushed already
     * @throws InvalidJournalEntryContent when the label is not 1 to 100 characters, or the push contradicts the first roll
     * @throws \LogicException            when the check rolled dice, not a pool
     */
    public function pushed(string $label, PushResult $result, array $changes): self
    {
        $pool = $this->pool ?? throw new \LogicException(\sprintf('Check "%s" rolled dice, only a pool roll can be pushed.', $this->checkKey));
        $push = CheckPushContent::fromResult($label, $result, $this->bandKey, $this->bandLabel, $changes);

        return self::of($this->characterId, $this->characterName, $this->checkKey, $this->checkLabel, $this->expression, $this->groups, $result->total, $this->modifier, $this->situational, $this->difficulty, $result->total, $result->band->key, $result->band->label, $this->hint, $this->modifierFormula, $this->modifierTerms, $pool->pushed($push, $this->situational));
    }

    /**
     * This check once the momentum of its challenge roll was burned (ADR 0049): the burn part is kept
     * beside the first roll, and the total and the band are those after the burn. The cost's sheet
     * changes are the ones the burn applied to the Character.
     *
     * @param list<SheetChange> $changes
     *
     * @throws CheckAlreadyPushed         when the roll was burned already
     * @throws InvalidJournalEntryContent when the label is not 1 to 100 characters, or the burn contradicts the first roll
     * @throws \LogicException            when the check rolled no challenge dice
     */
    public function burned(string $label, BurnResult $result, array $changes): self
    {
        $challenge = $this->challenge ?? throw new \LogicException(\sprintf('Check "%s" rolled no challenge dice, only a challenge roll can be burned.', $this->checkKey));
        $burn = CheckChallengeBurnContent::fromResult($label, $result, $this->bandKey, $this->bandLabel, $changes);

        return self::of($this->characterId, $this->characterName, $this->checkKey, $this->checkLabel, $this->expression, $this->groups, $this->diceTotal, $this->modifier, $this->situational, $this->difficulty, $result->total, $result->band->key, $result->band->label, $this->hint, $this->modifierFormula, $this->modifierTerms, null, $challenge->burned($burn));
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function characterId(): string
    {
        return $this->characterId;
    }

    public function characterName(): string
    {
        return $this->characterName;
    }

    public function checkKey(): string
    {
        return $this->checkKey;
    }

    public function checkLabel(): string
    {
        return $this->checkLabel;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * @return list<DiceGroupData> in notation order; each die in roll order, dropped ones included
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public function diceTotal(): int
    {
        return $this->diceTotal;
    }

    public function modifier(): int
    {
        return $this->modifier;
    }

    public function situational(): int
    {
        return $this->situational;
    }

    public function difficulty(): ?int
    {
        return $this->difficulty;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function bandKey(): string
    {
        return $this->bandKey;
    }

    public function bandLabel(): string
    {
        return $this->bandLabel;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }

    public function modifierFormula(): ?string
    {
        return $this->modifierFormula;
    }

    /**
     * @return list<ModifierTermData> one per reference of the formula, in the order it first names them
     */
    public function modifierTerms(): array
    {
        return $this->modifierTerms;
    }

    public function pool(): ?CheckPoolContent
    {
        return $this->pool;
    }

    public function challenge(): ?CheckChallengeContent
    {
        return $this->challenge;
    }

    /**
     * The special result of the roll as it stands (ADR 0044, ADR 0049): critical, messyCritical or bestialFailure
     * for a pool, match for challenge dice that all show the same face; null for none, for a dice check
     * and for entries stored before special results.
     */
    public function special(): ?string
    {
        return $this->challenge?->isMatch() ? SpecialResult::Match->value : $this->pool?->currentSpecial();
    }

    /**
     * @return array{kind: string, characterId: string, characterName: string, checkKey: string, checkLabel: string, expression: string, groups: list<DiceGroupData>, diceTotal: int, modifier: int, situational: int, difficulty: ?int, total: int, bandKey: string, bandLabel: string, hint: ?string, modifierFormula: ?string, modifierTerms: list<ModifierTermData>, pool?: PoolData, challenge?: ChallengeData}
     */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'characterId' => $this->characterId,
            'characterName' => $this->characterName,
            'checkKey' => $this->checkKey,
            'checkLabel' => $this->checkLabel,
            'expression' => $this->expression,
            'groups' => $this->groups,
            'diceTotal' => $this->diceTotal,
            'modifier' => $this->modifier,
            'situational' => $this->situational,
            'difficulty' => $this->difficulty,
            'total' => $this->total,
            'bandKey' => $this->bandKey,
            'bandLabel' => $this->bandLabel,
            'hint' => $this->hint,
            'modifierFormula' => $this->modifierFormula,
            'modifierTerms' => $this->modifierTerms,
        ] + ($this->pool instanceof CheckPoolContent ? ['pool' => $this->pool->toArray()] : []) + ($this->challenge instanceof CheckChallengeContent ? ['challenge' => $this->challenge->toArray()] : []);
    }
}
