<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\ValueResolver;
use App\Randomness\Domain\ChallengeDice;
use App\Randomness\Domain\ChallengeRoll;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidChallengeDice;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\InvalidDicePool;
use App\Randomness\Domain\PoolRoll;
use App\Randomness\Domain\PoolRollNotPushable;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Randomness\Domain\Roll;

/**
 * A saved check as a test roll evaluates it, in the order of Play's Check::perform (ADR 0024, 0032):
 * the effective difficulty, the situational modifier, the modifier, the band bounds, the dice, the
 * overflow guard, the band. Studio cannot import Play, so it composes the same shared-kernel pieces
 * itself. A check rolls dice or a pool (ADR 0041), never both; a pool check may declare a push, which
 * the sandbox applies to the faces of an earlier roll. It may instead score its dice against challenge
 * dice (ADR 0049): an action roll with dice, a progress roll without; its push is then a burn of the
 * momentum on the sample sheet. The saved draft already passed the item rules,
 * so nothing here enforces them again: bounds that do not increase for the samples are returned as
 * they are.
 */
final readonly class CheckTestRoll
{
    public const int MIN_SITUATIONAL = -100;
    public const int MAX_SITUATIONAL = 100;

    /**
     * @param non-empty-list<OutcomeBand> $bands in band order
     *
     * @throws \InvalidArgumentException when the check rolls both or neither of dice and a pool (a challenge replaces the pool and may add dice), a pool has a modifier, a challenge has a difficulty or a pool beside it, a momentum is not read beside exactly one action die, or a push has neither a pool nor a challenge with a momentum
     */
    public function __construct(
        public string $key,
        public ?DiceExpression $dice,
        public ?Formula $modifier,
        public ?CheckDifficultyRange $difficulty,
        public array $bands,
        public ?CheckTestPool $pool = null,
        public ?CheckTestPush $push = null,
        public ?CheckTestChallenge $challenge = null,
    ) {
        if ($challenge instanceof CheckTestChallenge) {
            $this->assertChallenge($key, $challenge, $dice, $pool, $difficulty);
        } elseif ($dice instanceof DiceExpression === $pool instanceof CheckTestPool) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls dice or a pool, exactly one of them.', $key));
        }

        if ($pool instanceof CheckTestPool && $modifier instanceof Formula) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls a pool, which takes no modifier.', $key));
        }

        if ($push instanceof CheckTestPush && !$pool instanceof CheckTestPool && null === $challenge?->momentum) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" declares a push without a pool or a challenge with a momentum.', $key));
        }
    }

    /**
     * Reads a check as the draft stores it, failing closed on anything the release contract would not
     * have let through.
     *
     * @param array<array-key, mixed> $check
     *
     * @throws CheckTestRollFailed when the stored check is not readable
     */
    public static function fromStored(array $check): self
    {
        $key = $check['key'] ?? null;
        if (!\is_string($key)) {
            throw CheckTestRollFailed::unreadable('a check has no string key');
        }

        $where = \sprintf('check "%s"', $key);
        $dice = $check['dice'] ?? null;
        $pool = $check['pool'] ?? null;
        $modifier = $check['modifier'] ?? null;
        $push = $check['push'] ?? null;
        $challenge = $check['challenge'] ?? null;
        if (null !== $challenge && null !== $pool) {
            throw CheckTestRollFailed::unreadable($where.' has both a challenge and a pool');
        }

        if (null !== $dice && null !== $pool) {
            throw CheckTestRollFailed::unreadable($where.' has both dice and a pool');
        }

        if (null === $dice && null === $pool && null === $challenge) {
            throw CheckTestRollFailed::unreadable($where.' has neither dice, a pool nor a challenge');
        }

        if (null !== $pool && null !== $modifier) {
            throw CheckTestRollFailed::unreadable($where.' has a modifier with a pool');
        }

        if (null !== $push && null === $pool && null === $challenge) {
            throw CheckTestRollFailed::unreadable($where.' has a push without a pool or a challenge');
        }

        try {
            return new self(
                $key,
                null === $dice ? null : DiceExpression::fromString(\is_string($dice) ? $dice : throw CheckTestRollFailed::unreadable($where.' has dice that are not a string')),
                null === $modifier ? null : Formula::fromString(\is_string($modifier) ? $modifier : throw CheckTestRollFailed::unreadable($where.' has a modifier that is not a string')),
                self::difficulty($check['difficulty'] ?? null, $where),
                self::bands($check['bands'] ?? null, $where),
                null === $pool ? null : self::readPool($pool, $where),
                null === $push ? null : self::readPush($push, $where),
                null === $challenge ? null : self::readChallenge($challenge, $where),
            );
        } catch (InvalidDiceExpression|InvalidFormula $invalid) {
            throw CheckTestRollFailed::unreadable(\sprintf('%s has %s that does not parse: %s', $where, $invalid instanceof InvalidDiceExpression ? 'dice' : 'a formula', rtrim($invalid->getMessage(), '.')), $invalid);
        } catch (\InvalidArgumentException|InvalidChallengeDice $invalid) {
            // Whatever invariant of the check or its parts a stored draft breaks: unreadable, never a 500.
            throw CheckTestRollFailed::unreadable(\sprintf('%s breaks the contract: %s', $where, rtrim($invalid->getMessage(), '.')), $invalid);
        }
    }

    /**
     * Rolls the dice or the pool and picks the band. The modifier or the pool count and every band
     * bound are evaluated first, so a check that cannot be evaluated rolls nothing.
     *
     * @param ?int $difficulty the Check difficulty chosen for the roll; omitted, the declared default applies
     *
     * @throws InvalidTestRoll     when a difficulty is given and the check declares none or it is outside the declared range, or the situational modifier is outside -100..100
     * @throws CheckTestRollFailed when a formula or the dice cannot be evaluated, or the total overflows
     */
    public function perform(RandomNumberGenerator $random, ValueResolver $sheet, ?int $difficulty, int $situational): CheckTestRollResult
    {
        $effective = $this->effectiveDifficulty($difficulty);
        $this->assertSituational($situational);

        try {
            $modifier = $this->modifier?->evaluate(new CheckFormulaValues($sheet, null)) ?? 0;
            $count = $this->countOf($sheet);
            $hunger = $this->hungerOf($sheet);
            $bounds = $this->boundsOf($sheet, $effective);
            $momentum = $this->momentumOf($sheet);
            $roll = $this->dice?->roll($random);
            $challengeRoll = $this->challenge?->dice->roll($random);
            $poolRoll = $this->pool?->pool($this->pool->sizeFor($count, $situational), $hunger)->roll($random);
        } catch (FormulaEvaluationFailed|InvalidDiceExpression $failed) {
            throw CheckTestRollFailed::evaluation($this->key, $failed);
        }

        if ($this->pool instanceof CheckTestPool && $poolRoll instanceof PoolRoll) {
            $total = $poolRoll->total();
            $band = $this->bandFor($bounds, $total);

            return new CheckTestRollResult(null, 0, $situational, $effective, $total, $this->bands, $bounds, $band, $this->poolRollOf($poolRoll, $count), $this->push, null, $this->specialOf($poolRoll, $total, $effective, $band));
        }

        if ($this->challenge instanceof CheckTestChallenge && $challengeRoll instanceof ChallengeRoll) {
            return $this->challengeResult($this->challenge, $challengeRoll, $roll, $modifier, $situational, $momentum, $bounds);
        }

        $roll ?? throw new \LogicException(\sprintf('Check "%s" rolls neither dice nor a pool.', $this->key));

        $total = $this->scoreOf($roll->total(), $modifier, $situational);

        return new CheckTestRollResult($roll, $modifier, $situational, $effective, $total, $this->bands, $bounds, $this->bandFor($bounds, $total));
    }

    /**
     * Pushes the roll a client holds (ADR 0041), statelessly: the pool is sized again for these samples,
     * rebuilt from the faces of the roll (the Hunger dice last, as rolled), and every regular die that
     * failed is re-rolled once, at most the push's cap and never a Hunger die (ADR 0044). The band and
     * the special result are picked again for the pushed roll. The cost is not applied: a test roll
     * has no campaign.
     *
     * A challenge check's push is a burn instead (ADR 0049): no dice are rolled, so the generator is not
     * used. The faces are the action die, then the challenge dice. The score is rebuilt from the action
     * die and the samples, and the burn is refused unless the momentum, capped, beats more dice than it.
     * The special result stays as rolled and the bands are read again on the burned total.
     *
     * @param list<int> $faces the face of each die of the roll as it was shown, in roll order, Hunger dice last; a challenge roll's action die first
     *
     * @throws CheckTestRollNotPushable when the check declares no push, a challenge roll is a progress roll or its momentum beats no more dice than its score, or no regular die of the roll failed
     * @throws InvalidTestRoll          when the difficulty or the situational modifier is not accepted, or the faces are not the roll of this pool
     * @throws CheckTestRollFailed      when the pool count or a band bound cannot be evaluated; nothing is re-rolled
     */
    public function push(RandomNumberGenerator $random, ValueResolver $sheet, ?int $difficulty, int $situational, array $faces): CheckTestRollResult
    {
        if ($this->challenge instanceof CheckTestChallenge) {
            return $this->burn($this->challenge, $sheet, $difficulty, $situational, $faces);
        }

        if (!$this->pool instanceof CheckTestPool || !$this->push instanceof CheckTestPush) {
            throw CheckTestRollNotPushable::noPush($this->key);
        }

        $effective = $this->effectiveDifficulty($difficulty);
        $this->assertSituational($situational);

        try {
            $count = $this->countOf($sheet);
            $hunger = $this->hungerOf($sheet);
            $bounds = $this->boundsOf($sheet, $effective);
        } catch (FormulaEvaluationFailed $failed) {
            throw CheckTestRollFailed::evaluation($this->key, $failed);
        }

        try {
            $rolled = PoolRoll::ofFaces($this->pool->pool($this->pool->sizeFor($count, $situational), $hunger), $faces);
        } catch (InvalidDicePool $invalid) {
            throw InvalidTestRoll::at('faces', rtrim($invalid->getMessage(), '.'));
        }

        try {
            $pushed = $rolled->push($random, $this->push->maxDice);
        } catch (PoolRollNotPushable) {
            throw CheckTestRollNotPushable::noFailedDie($this->key);
        }

        $rolledBand = $this->bandFor($bounds, $rolled->total());
        $band = $this->bandFor($bounds, $pushed->total());

        return new CheckTestRollResult(null, 0, $situational, $effective, $pushed->total(), $this->bands, $bounds, $band, $this->poolRollOf($pushed, $count), $this->push, $rolled->total(), $this->specialOf($pushed, $pushed->total(), $effective, $band), $this->specialOf($rolled, $rolled->total(), $effective, $rolledBand));
    }

    /**
     * A challenge roll (ADR 0049): the score is the action die (0 when negative momentum cancels it), the
     * modifier and the situational modifier, capped at the challenge's score cap; the total is the dice it beats.
     *
     * @param list<?int> $bounds the evaluated upper bounds, in band order
     */
    private function challengeResult(CheckTestChallenge $challenge, ChallengeRoll $challengeRoll, ?Roll $roll, int $modifier, int $situational, ?int $momentum, array $bounds): CheckTestRollResult
    {
        $actionDie = $roll?->total();
        [$score, $cancelled] = $this->firstScore($challenge, $actionDie, $momentum, $modifier, $situational);
        $total = $challengeRoll->beatenBy($score);

        return new CheckTestRollResult($roll, $modifier, $situational, null, $total, $this->bands, $bounds, $this->bandFor($bounds, $total), null, $this->push, null, $challengeRoll->isMatch() ? CheckTestSpecialResult::Match : null, null, new CheckTestChallengeRoll($challengeRoll, $actionDie, $score, $challenge->scoreMax, $momentum, $challenge->momentum, $cancelled));
    }

    /**
     * Burns the momentum of a challenge roll the client holds: no dice are rolled.
     *
     * @param list<int> $faces the action die, then the challenge dice
     */
    private function burn(CheckTestChallenge $challenge, ValueResolver $sheet, ?int $difficulty, int $situational, array $faces): CheckTestRollResult
    {
        if (!$this->push instanceof CheckTestPush || null === $challenge->momentum || !$this->dice instanceof DiceExpression) {
            throw CheckTestRollNotPushable::noPush($this->key);
        }

        $this->effectiveDifficulty($difficulty);
        $this->assertSituational($situational);

        try {
            $modifier = $this->modifier?->evaluate(new CheckFormulaValues($sheet, null)) ?? 0;
            $bounds = $this->boundsOf($sheet, null);
            $momentum = $this->momentumOf($sheet) ?? throw new \LogicException('A burn reads a momentum.');
        } catch (FormulaEvaluationFailed $failed) {
            throw CheckTestRollFailed::evaluation($this->key, $failed);
        }

        $actionDie = $this->actionDieOf($faces, $challenge, $this->dice);
        try {
            $rolled = ChallengeRoll::ofFaces($challenge->dice, \array_slice($faces, 1));
        } catch (InvalidChallengeDice $invalid) {
            throw InvalidTestRoll::at('faces', rtrim($invalid->getMessage(), '.'));
        }

        [$first, $cancelled] = $this->firstScore($challenge, $actionDie, $momentum, $modifier, $situational);
        $score = $challenge->burnScore($momentum);
        if ($rolled->beatenBy($score) <= $rolled->beatenBy($first)) {
            throw CheckTestRollNotPushable::noBetterBurn($this->key);
        }

        $total = $rolled->beatenBy($score);
        $match = $rolled->isMatch() ? CheckTestSpecialResult::Match : null;

        return new CheckTestRollResult(null, $modifier, $situational, null, $total, $this->bands, $bounds, $this->bandFor($bounds, $total), null, $this->push, $rolled->beatenBy($first), $match, $match, new CheckTestChallengeRoll($rolled, $actionDie, $score, $challenge->scoreMax, $momentum, $challenge->momentum, $cancelled, new CheckTestBurn($momentum, $score)));
    }

    /**
     * The action die of the faces of a burned roll: the first, in 1..the sides of the one die.
     *
     * @param list<int> $faces
     *
     * @throws InvalidTestRoll when there are not as many faces as the action die and the challenge dice, or the action die is outside its sides
     */
    private function actionDieOf(array $faces, CheckTestChallenge $challenge, DiceExpression $dice): int
    {
        if (\count($faces) !== 1 + $challenge->dice->count) {
            throw InvalidTestRoll::at('faces', \sprintf('must be %d faces, the action die then the challenge dice, got %d', 1 + $challenge->dice->count, \count($faces)));
        }

        $sides = $dice->sidesOfOneDie() ?? throw CheckTestRollNotPushable::noPush($this->key);
        if ($faces[0] < 1 || $faces[0] > $sides) {
            throw InvalidTestRoll::at('faces', \sprintf('the action die must be 1 to %d, got %d', $sides, $faces[0]));
        }

        return $faces[0];
    }

    /**
     * The score before any burn and whether negative momentum cancelled the action die: the die (0 when
     * cancelled), the modifier and the situational modifier, capped at the score cap.
     *
     * @return array{int, bool}
     */
    private function firstScore(CheckTestChallenge $challenge, ?int $actionDie, ?int $momentum, int $modifier, int $situational): array
    {
        $dice = $actionDie ?? 0;
        $cancelled = null !== $momentum && $momentum < 0 && -$momentum === $dice;
        $score = $this->scoreOf($cancelled ? 0 : $dice, $modifier, $situational);

        return [null === $challenge->scoreMax ? $score : min($score, $challenge->scoreMax), $cancelled];
    }

    /**
     * The dice total plus the modifiers.
     *
     * @throws CheckTestRollFailed when the sum leaves the integers
     */
    private function scoreOf(int $dice, int $modifier, int $situational): int
    {
        // The dice total can be close to PHP_INT_MAX, so adding the modifiers can leave the integers (as in the dice kernel).
        if (abs((float) $dice + $modifier + $situational) >= (float) \PHP_INT_MAX) {
            throw CheckTestRollFailed::evaluation($this->key, new \OverflowException('the total of the check overflows.'));
        }

        return $dice + $modifier + $situational;
    }

    /**
     * What the challenge's momentum field reads for the samples; null when the check names none.
     *
     * @throws FormulaEvaluationFailed
     */
    private function momentumOf(ValueResolver $sheet): ?int
    {
        $reference = $this->challenge?->momentumReference();

        return $reference instanceof \App\Formula\Domain\Reference ? new CheckFormulaValues($sheet, null)->value($reference) : null;
    }

    private function assertSituational(int $situational): void
    {
        if ($situational < self::MIN_SITUATIONAL || $situational > self::MAX_SITUATIONAL) {
            throw InvalidTestRoll::outside('situational', self::MIN_SITUATIONAL, self::MAX_SITUATIONAL, $situational);
        }
    }

    /**
     * What the pool's count reads for the samples: its number, or its formula over the sheet (without
     * a difficulty, as Play); 0 for a check that rolls dice.
     *
     * @throws FormulaEvaluationFailed
     */
    private function countOf(ValueResolver $sheet): int
    {
        if (!$this->pool instanceof CheckTestPool) {
            return 0;
        }

        return $this->pool->count instanceof Formula ? $this->pool->count->evaluate(new CheckFormulaValues($sheet, null)) : $this->pool->count;
    }

    /**
     * What the pool's Hunger count reads for the samples: its number, or its formula over the sheet
     * (without a difficulty, as the count); 0 without Hunger dice. The pool clamps it to its size, so
     * a negative one rolls none.
     *
     * @throws FormulaEvaluationFailed
     */
    private function hungerOf(ValueResolver $sheet): int
    {
        $hunger = $this->pool?->hunger;

        return $hunger instanceof Formula ? $hunger->evaluate(new CheckFormulaValues($sheet, null)) : $hunger ?? 0;
    }

    /**
     * The special result of a roll (ADR 0044), as Play's Check::specialOf(). A roll fails when its total
     * is below the difficulty, if the check declares one, else when its band is the first of a check
     * with two bands or more; a check with a single band never fails.
     */
    private function specialOf(PoolRoll $roll, int $total, ?int $difficulty, OutcomeBand $band): ?CheckTestSpecialResult
    {
        $failed = null !== $difficulty ? $total < $difficulty : \count($this->bands) >= 2 && $band === $this->bands[0];

        return CheckTestSpecialResult::of($roll, $failed);
    }

    /**
     * @return list<?int> the evaluated upper bound of each band, in band order
     *
     * @throws FormulaEvaluationFailed
     */
    private function boundsOf(ValueResolver $sheet, ?int $difficulty): array
    {
        $values = new CheckFormulaValues($sheet, $difficulty);

        return array_map(static fn (OutcomeBand $band): ?int => $band->upTo instanceof Formula ? $band->upTo->evaluate($values) : $band->upTo, $this->bands);
    }

    /**
     * The first band whose upper bound is at least the total. The last band has no bound and catches
     * the rest, so a band always matches.
     *
     * @param list<?int> $bounds
     */
    private function bandFor(array $bounds, int $total): OutcomeBand
    {
        return $this->bands[array_find_key($bounds, static fn (?int $bound): bool => null === $bound || $total <= $bound) ?? \count($this->bands) - 1];
    }

    private function poolRollOf(PoolRoll $roll, int $count): CheckTestPoolRoll
    {
        return new CheckTestPoolRoll($roll, $count, $this->pool?->count instanceof Formula ? $this->pool->count->toString() : null, $this->pool?->hunger instanceof Formula ? $this->pool->hunger->toString() : null);
    }

    private function effectiveDifficulty(?int $given): ?int
    {
        if (!$this->difficulty instanceof CheckDifficultyRange) {
            return null === $given ? null : throw InvalidTestRoll::at('difficulty', \sprintf('check "%s" declares no difficulty', $this->key));
        }

        if (null === $given) {
            return $this->difficulty->default;
        }

        if ($this->difficulty->allows($given)) {
            return $given;
        }

        throw match (true) {
            null !== $this->difficulty->min && null !== $this->difficulty->max => InvalidTestRoll::outside('difficulty', $this->difficulty->min, $this->difficulty->max, $given),
            null !== $this->difficulty->min => InvalidTestRoll::at('difficulty', \sprintf('must be at least %d, got %d', $this->difficulty->min, $given)),
            default => InvalidTestRoll::at('difficulty', \sprintf('must be at most %d, got %d', $this->difficulty->max ?? 0, $given)),
        };
    }

    private static function difficulty(mixed $stored, string $where): ?CheckDifficultyRange
    {
        if (null === $stored) {
            return null;
        }

        $default = \is_array($stored) ? $stored['default'] ?? null : null;
        $min = \is_array($stored) ? $stored['min'] ?? null : null;
        $max = \is_array($stored) ? $stored['max'] ?? null : null;
        if (!\is_int($default) || (null !== $min && !\is_int($min)) || (null !== $max && !\is_int($max))) {
            throw CheckTestRollFailed::unreadable($where.' has a difficulty without an integer default, min and max');
        }

        return new CheckDifficultyRange($default, $min, $max);
    }

    /**
     * @throws CheckTestRollFailed       when the pool is not an object with a count (an integer or a formula), integer sides and an integer success threshold
     * @throws \InvalidArgumentException when the pool is outside the kernel's bounds
     * @throws InvalidFormula            when the count formula does not parse
     */
    private static function readPool(mixed $stored, string $where): CheckTestPool
    {
        if (!\is_array($stored) || array_is_list($stored)) {
            throw CheckTestRollFailed::unreadable($where.' has a pool that is not an object');
        }

        $count = $stored['count'] ?? null;
        $sides = $stored['sides'] ?? null;
        $successAt = $stored['successAt'] ?? null;
        if (!\is_int($sides) || !\is_int($successAt)) {
            throw CheckTestRollFailed::unreadable($where.' has a pool without integer sides and success threshold');
        }

        $count = self::readCount($count, $where, 'pool count');
        if (null === $count) {
            throw CheckTestRollFailed::unreadable($where.' has a pool count that is neither an integer nor a formula');
        }

        $hunger = null;
        if (\array_key_exists('hunger', $stored) && null !== $stored['hunger']) {
            $hungry = $stored['hunger'];
            $hunger = \is_array($hungry) && !array_is_list($hungry) ? self::readCount($hungry['count'] ?? null, $where, 'Hunger count') : null;
            if (null === $hunger) {
                throw CheckTestRollFailed::unreadable($where.' has Hunger dice without a count that is an integer or a formula');
            }
        }

        $criticals = $stored['criticals'] ?? null;
        $bonus = 0;
        if (null !== $criticals) {
            $bonus = \is_array($criticals) && !array_is_list($criticals) ? $criticals['bonus'] ?? null : null;
            if (!\is_int($bonus) || $bonus < 1) {
                throw CheckTestRollFailed::unreadable($where.' has critical pairs without an integer bonus from 1 to '.CheckTestPool::MAX_CRITICALS_BONUS);
            }
        }

        // Only the pool's own bounds are reported as a pool that breaks the contract: any other error keeps its own label.
        try {
            return new CheckTestPool($count, $sides, $successAt, $hunger, $bonus);
        } catch (\InvalidArgumentException $invalid) {
            throw CheckTestRollFailed::unreadable(\sprintf('%s has a pool that breaks the contract: %s', $where, rtrim($invalid->getMessage(), '.')), $invalid);
        }
    }

    /**
     * A count as the release stores it: an integer, or an object with a formula that parses.
     *
     * @throws InvalidFormula when the formula does not parse
     */
    private static function readCount(mixed $stored, string $where, string $what): int|Formula|null
    {
        if (\is_int($stored)) {
            return $stored;
        }

        if (!\is_array($stored) || array_is_list($stored)) {
            return null;
        }

        $formula = $stored['formula'] ?? null;

        return Formula::fromString(\is_string($formula) ? $formula : throw CheckTestRollFailed::unreadable(\sprintf('%s has a %s without a formula', $where, $what)));
    }

    /**
     * @throws \InvalidArgumentException when the challenge breaks the shape of ADR 0049
     */
    private function assertChallenge(string $key, CheckTestChallenge $challenge, ?DiceExpression $dice, ?CheckTestPool $pool, ?CheckDifficultyRange $difficulty): void
    {
        if ($pool instanceof CheckTestPool) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls challenge dice or a pool, not both.', $key));
        }

        if ($difficulty instanceof CheckDifficultyRange) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls challenge dice and has no difficulty, the dice are the difficulty.', $key));
        }

        if (null !== $challenge->momentum && !$dice?->rollsOneDie()) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" names a momentum and needs dice that roll exactly one die.', $key));
        }
    }

    /**
     * @throws CheckTestRollFailed       when the challenge is not an object with integer count and sides, and an integer cap and a string momentum if it has them
     * @throws \InvalidArgumentException when the cap is outside its bounds
     * @throws InvalidChallengeDice      when the dice are outside the kernel's bounds
     * @throws InvalidFormula            when the momentum is not a field key
     */
    private static function readChallenge(mixed $stored, string $where): CheckTestChallenge
    {
        if (!\is_array($stored) || array_is_list($stored)) {
            throw CheckTestRollFailed::unreadable($where.' has a challenge that is not an object');
        }

        $count = $stored['count'] ?? null;
        $sides = $stored['sides'] ?? null;
        $scoreMax = $stored['scoreMax'] ?? null;
        $momentum = $stored['momentum'] ?? null;
        if (!\is_int($count) || !\is_int($sides)) {
            throw CheckTestRollFailed::unreadable($where.' has challenge dice without an integer count and sides');
        }

        if (null !== $scoreMax && !\is_int($scoreMax)) {
            throw CheckTestRollFailed::unreadable($where.' has a challenge score cap that is not an integer');
        }

        if (null !== $momentum && !\is_string($momentum)) {
            throw CheckTestRollFailed::unreadable($where.' has a challenge momentum that is not a string');
        }

        return new CheckTestChallenge(new ChallengeDice($count, $sides), $scoreMax, $momentum);
    }

    private static function readPush(mixed $stored, string $where): CheckTestPush
    {
        $label = \is_array($stored) ? $stored['label'] ?? null : null;
        if (!\is_string($label) || '' === trim($label)) {
            throw CheckTestRollFailed::unreadable($where.' has a push without a label');
        }

        if (mb_strlen($label) > CheckTestPush::MAX_LABEL) {
            throw CheckTestRollFailed::unreadable(\sprintf('%s has a push that breaks the contract: the label is longer than %d characters', $where, CheckTestPush::MAX_LABEL));
        }

        // The cost is never applied in a test roll, but a push that is not an object with a list of effects is not a push.
        $effects = $stored['effects'] ?? [];
        if (!\is_array($effects) || !array_is_list($effects)) {
            throw CheckTestRollFailed::unreadable($where.' has push effects that are not a list');
        }

        if (\count($effects) > CheckTestPush::MAX_EFFECTS || array_any($effects, static fn (mixed $effect): bool => !\is_array($effect) || array_is_list($effect))) {
            throw CheckTestRollFailed::unreadable(\sprintf('%s has a push that breaks the contract: at most %d effects, each an object', $where, CheckTestPush::MAX_EFFECTS));
        }

        // A cap of 0 or less would not be a push: the contract keeps 1..100, and a bad one is unreadable, never ignored.
        $maxDice = $stored['maxDice'] ?? null;
        if (null !== $maxDice && !\is_int($maxDice)) {
            throw CheckTestRollFailed::unreadable($where.' has a push cap that is not an integer');
        }

        return new CheckTestPush($label, $maxDice);
    }

    /**
     * @return non-empty-list<OutcomeBand>
     */
    private static function bands(mixed $stored, string $where): array
    {
        if (!\is_array($stored) || [] === $stored || !array_is_list($stored)) {
            throw CheckTestRollFailed::unreadable($where.' has no list of bands');
        }

        $bands = [];
        foreach ($stored as $index => $band) {
            $key = \is_array($band) ? $band['key'] ?? null : null;
            $label = \is_array($band) ? $band['label'] ?? null : null;
            if (!\is_string($key) || !\is_string($label)) {
                throw CheckTestRollFailed::unreadable(\sprintf('%s has a band %d without a string key and label', $where, $index));
            }

            $bands[] = new OutcomeBand($key, $label, self::bound($band['upTo'] ?? null, \sprintf('%s band "%s"', $where, $key)));
        }

        return $bands;
    }

    private static function bound(mixed $stored, string $where): int|Formula|null
    {
        if (null === $stored || \is_int($stored)) {
            return $stored;
        }

        // A tracker bound is the formula {tracker:key}, as Play reads it.
        $tracker = \is_array($stored) ? $stored['tracker'] ?? null : null;
        $formula = \is_array($stored) ? $stored['formula'] ?? null : null;
        $source = match (true) {
            \is_string($formula) => $formula,
            \is_string($tracker) => '{tracker:'.$tracker.'}',
            default => throw CheckTestRollFailed::unreadable($where.' has an upper bound that is neither an integer, a tracker nor a formula'),
        };

        return Formula::fromString($source);
    }
}
