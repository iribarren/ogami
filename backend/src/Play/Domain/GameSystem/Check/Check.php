<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ValueResolver;
use App\Randomness\Domain\ChallengeRoll;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\PoolRoll;
use App\Randomness\Domain\PoolRollNotPushable;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Randomness\Domain\Roll;

/**
 * A Check of a GameSystem release (ADR 0024): a dice expression, an optional formula modifier over
 * the sheet, an optional Check difficulty the player sets at roll time, and Outcome bands. The total
 * is the dice, plus the modifier, plus the situational modifier; the first band whose upper bound
 * is at least the total is the outcome, and the last band catches the rest.
 *
 * A check rolls a dice expression or a pool, never both (ADR 0041). A pool check has no modifier:
 * the situational modifier adds or removes dice, and the total is the number of successes. A pool
 * check can declare a push, which re-rolls the dice that failed for a cost.
 *
 * A challenge check (ADR 0049) compares a score against the challenge dice: with a dice expression it is
 * an action roll (the dice, the modifier and the situational modifier), without one a progress roll
 * (the modifier alone, which it requires). The total is the number of challenge dice the score beats.
 * An action roll that names a momentum can declare a push, which burns it (no dice are rolled).
 */
final readonly class Check
{
    public const int MIN_SITUATIONAL = -100;
    public const int MAX_SITUATIONAL = 100;

    /**
     * @param list<CheckBand> $bands in band order, unique keys, only the last without an upper bound
     *
     * @throws \InvalidArgumentException when there is neither dice nor a pool nor a challenge or dice and a pool, a pool check has a modifier or a challenge, a challenge check has a difficulty, a push without a momentum or with a maxDice,
     *                                   a progress roll has no modifier or a momentum, a momentum needs dice that are not exactly one die, a check with neither a pool nor a challenge declares a push, there is no band,
     *                                   two bands share a key, a band but the last has no upper bound, the last has one,
     *                                   or literal upper bounds do not strictly increase
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint,
        public ?DiceExpression $dice,
        public ?Formula $modifier,
        public ?CheckDifficulty $difficulty,
        public array $bands,
        public ?CheckPool $pool = null,
        public ?CheckPush $push = null,
        public ?CheckChallenge $challenge = null,
    ) {
        if ($pool instanceof CheckPool && $challenge instanceof CheckChallenge) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls a pool or challenge dice, not both.', $key));
        }

        if (!$challenge instanceof CheckChallenge && $dice instanceof DiceExpression === $pool instanceof CheckPool) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls either a dice expression or a pool.', $key));
        }

        if ($challenge instanceof CheckChallenge) {
            $this->assertChallenge($challenge);
        }

        if ($pool instanceof CheckPool && $modifier instanceof Formula) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls a pool and has no modifier.', $key));
        }

        if ($push instanceof CheckPush && !$pool instanceof CheckPool && !$challenge instanceof CheckChallenge) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" declares a push without rolling a pool or challenge dice.', $key));
        }

        if ([] === $bands) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" needs at least one band.', $key));
        }

        $keys = [];
        $previous = null;
        foreach ($bands as $index => $band) {
            if (isset($keys[$band->key])) {
                throw new \InvalidArgumentException(\sprintf('Check "%s" lists band "%s" twice.', $key, $band->key));
            }

            $keys[$band->key] = true;
            if (array_key_last($bands) === $index) {
                if (null !== $band->upTo) {
                    throw new \InvalidArgumentException(\sprintf('The last band of check "%s" catches the rest and has no upper bound.', $key));
                }

                continue;
            }

            if (null === $band->upTo) {
                throw new \InvalidArgumentException(\sprintf('Band "%s" of check "%s" needs an upper bound; only the last band has none.', $band->key, $key));
            }
            if (\is_int($band->upTo)) {
                if (null !== $previous && $band->upTo <= $previous) {
                    throw new \InvalidArgumentException(\sprintf('Band "%s" of check "%s" must be above the previous upper bound %d.', $band->key, $key, $previous));
                }
                $previous = $band->upTo;
            }
        }
    }

    /**
     * Rolls the dice or the pool and picks the band. The modifier or the pool count and every band
     * bound are evaluated first, so a check that cannot be evaluated rolls nothing.
     *
     * @param ?int $difficulty the Check difficulty chosen at roll time; omitted, the declared default applies
     *
     * @throws CheckDifficultyNotDeclared    when a difficulty is given and the check declares none
     * @throws CheckDifficultyOutOfRange     when the difficulty is outside the declared min..max
     * @throws SituationalModifierOutOfRange when the situational modifier is outside -100..100
     * @throws CheckEvaluationFailed         when a formula or the dice cannot be evaluated, or the total overflows
     */
    public function perform(RandomNumberGenerator $random, ValueResolver $sheet, ?int $difficulty, int $situational): CheckResult
    {
        $effective = $this->effectiveDifficulty($difficulty);
        if ($situational < self::MIN_SITUATIONAL || $situational > self::MAX_SITUATIONAL) {
            throw SituationalModifierOutOfRange::of($situational);
        }

        try {
            $sheetValues = new CheckValues($sheet, null);
            $modifier = $this->modifier?->evaluate($sheetValues) ?? 0;
            $terms = $this->termsOf($this->modifier, $sheetValues);
            $count = $this->pool instanceof CheckPool ? $this->countOf($this->pool, $sheetValues) : 0;
            $bandValues = new CheckValues($sheet, $effective);
            $bounds = array_map(static fn (CheckBand $band): ?int => $band->upTo instanceof Formula ? $band->upTo->evaluate($bandValues) : $band->upTo, $this->bands);
            $momentum = $this->challenge?->momentum;
            $momentumValue = null === $momentum ? null : $sheetValues->value(Reference::field($momentum));
            $roll = $this->dice?->roll($random);
            $challengeRoll = $this->challenge?->dice()->roll($random);
            $hunger = $this->pool instanceof CheckPool ? $this->hungerOf($this->pool, $sheetValues) : 0;
            $poolRoll = $this->pool instanceof CheckPool ? new DicePool(CheckPool::sizeFor($count, $situational), $this->pool->sides, $this->pool->successAt, $hunger, $this->pool->criticals ?? 0)->roll($random) : null;
        } catch (FormulaEvaluationFailed|InvalidDiceExpression $failed) {
            throw CheckEvaluationFailed::of($this->key, $failed);
        }

        if ($poolRoll instanceof PoolRoll) {
            $total = $poolRoll->total();
            $band = $this->bandFor($bounds, $total);

            return new CheckResult(null, 0, $situational, $effective, $total, $band, [], new PoolResult($count, $poolRoll, $this->termsOf($this->pool->count, $sheetValues)), $this->specialOf($poolRoll, $total, $effective, $band));
        }

        if ($challengeRoll instanceof ChallengeRoll) {
            return $this->challengeResult($this->challenge->scoreMax, $challengeRoll, $roll, $modifier, $situational, $momentumValue, $bounds, $terms);
        }

        $roll ??= throw new \LogicException(\sprintf('Check "%s" rolls neither dice nor a pool.', $this->key));

        $total = $this->scoreOf($roll->total(), $modifier, $situational);

        return new CheckResult($roll, $modifier, $situational, $effective, $total, $this->bandFor($bounds, $total), $terms);
    }

    /**
     * A challenge roll (ADR 0049): the score is the action die (0 when negative momentum cancels it), the
     * modifier and the situational modifier, capped at the challenge's score cap; the total is the dice it beats.
     *
     * @param list<?int>         $bounds the evaluated upper bounds, in band order
     * @param list<ModifierTerm> $terms
     */
    private function challengeResult(?int $scoreMax, ChallengeRoll $challengeRoll, ?Roll $roll, int $modifier, int $situational, ?int $momentum, array $bounds, array $terms): CheckResult
    {
        $dice = $roll?->total() ?? 0;
        $cancelled = null !== $momentum && $momentum < 0 && -$momentum === $dice;
        $score = $this->scoreOf($cancelled ? 0 : $dice, $modifier, $situational);
        $score = null === $scoreMax ? $score : min($score, $scoreMax);
        $total = $challengeRoll->beatenBy($score);

        return new CheckResult($roll, $modifier, $situational, null, $total, $this->bandFor($bounds, $total), $terms, null, $challengeRoll->isMatch() ? SpecialResult::Match : null, new ChallengeResult($challengeRoll, $score, $momentum, $cancelled));
    }

    /**
     * The dice total plus the modifiers.
     *
     * @throws CheckEvaluationFailed when the sum leaves the integers
     */
    private function scoreOf(int $dice, int $modifier, int $situational): int
    {
        // The dice total can be close to PHP_INT_MAX, so adding the modifiers can leave the integers (as in the dice kernel).
        if (abs((float) $dice + $modifier + $situational) >= (float) \PHP_INT_MAX) {
            throw CheckEvaluationFailed::of($this->key, new \OverflowException('the total of the check overflows.'));
        }

        return $dice + $modifier + $situational;
    }

    /**
     * @throws \InvalidArgumentException when the challenge breaks the shape of ADR 0049
     */
    private function assertChallenge(CheckChallenge $challenge): void
    {
        if ($this->difficulty instanceof CheckDifficulty) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls challenge dice and has no difficulty, the dice are the difficulty.', $this->key));
        }

        if ($this->push instanceof CheckPush) {
            if (null === $challenge->momentum) {
                throw new \InvalidArgumentException(\sprintf('Check "%s" rolls challenge dice and declares a push, which burns momentum: it needs a momentum.', $this->key));
            }

            if (null !== $this->push->maxDice) {
                throw new \InvalidArgumentException(\sprintf('Check "%s" burns momentum and rolls no dice: its push has no maxDice.', $this->key));
            }
        }

        if (!$this->dice instanceof DiceExpression) {
            if (!$this->modifier instanceof Formula) {
                throw new \InvalidArgumentException(\sprintf('Check "%s" is a progress roll and needs a modifier, its score.', $this->key));
            }

            if (null !== $challenge->momentum) {
                throw new \InvalidArgumentException(\sprintf('Check "%s" is a progress roll and has no momentum.', $this->key));
            }
        } elseif (null !== $challenge->momentum && !$this->dice->rollsOneDie()) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" names a momentum and needs dice that roll exactly one die.', $this->key));
        }
    }

    /**
     * Pushes a pool roll (ADR 0041): re-rolls the regular dice that failed, once (at most the push's
     * cap, never Hunger dice, ADR 0044), and picks the band and the special result again
     * for the new successes. Every band bound is evaluated first, against the current sheet and the
     * difficulty the roll had, so a check that cannot be evaluated re-rolls nothing. The cost is not
     * applied here.
     *
     * @param PoolRoll $roll       the roll as it was recorded
     * @param ?int     $difficulty the Check difficulty that applied to the roll
     *
     * @throws CheckNotPushable      when the check declares no push, or every die of the roll succeeded
     * @throws CheckEvaluationFailed when a band bound cannot be evaluated
     */
    public function push(RandomNumberGenerator $random, ValueResolver $sheet, PoolRoll $roll, ?int $difficulty): PushResult
    {
        if (!$this->push instanceof CheckPush) {
            throw CheckNotPushable::noPush($this->key);
        }

        try {
            $bandValues = new CheckValues($sheet, $difficulty);
            $bounds = array_map(static fn (CheckBand $band): ?int => $band->upTo instanceof Formula ? $band->upTo->evaluate($bandValues) : $band->upTo, $this->bands);
        } catch (FormulaEvaluationFailed $failed) {
            throw CheckEvaluationFailed::of($this->key, $failed);
        }

        try {
            $pushed = $roll->push($random, $this->push->maxDice);
        } catch (PoolRollNotPushable) {
            throw CheckNotPushable::noFailedDie($this->key);
        }

        $band = $this->bandFor($bounds, $pushed->total());

        return new PushResult($pushed, $pushed->total(), $band, $this->specialOf($pushed, $pushed->total(), $difficulty, $band));
    }

    /**
     * Burns momentum on a challenge roll (ADR 0049): no dice are rolled, the challenge dice stay as they
     * were, and the score becomes the momentum value now on the sheet (capped), which is the larger of the
     * two scores. Every band bound is evaluated first, so a check that cannot be evaluated burns nothing.
     * The cost is not applied here.
     *
     * @param ChallengeRoll $roll       the challenge dice as they were recorded
     * @param int           $firstScore the score of the roll as it was recorded
     *
     * @throws CheckNotPushable      when the check declares no burn, or the momentum now beats no more dice than the first score
     * @throws CheckEvaluationFailed when the momentum or a band bound cannot be evaluated
     */
    public function burn(ValueResolver $sheet, ChallengeRoll $roll, int $firstScore): BurnResult
    {
        $momentumKey = $this->challenge?->momentum;
        if (!$this->push instanceof CheckPush || null === $momentumKey) {
            throw CheckNotPushable::noPush($this->key);
        }

        try {
            $values = new CheckValues($sheet, null);
            $momentum = $values->value(Reference::field($momentumKey));
            $bounds = array_map(static fn (CheckBand $band): ?int => $band->upTo instanceof Formula ? $band->upTo->evaluate($values) : $band->upTo, $this->bands);
        } catch (FormulaEvaluationFailed $failed) {
            throw CheckEvaluationFailed::of($this->key, $failed);
        }

        if (!$this->burnImproves($roll, $firstScore, $momentum)) {
            throw CheckNotPushable::noBetterBurn($this->key);
        }

        $score = $this->burnScore($momentum);
        $total = $roll->beatenBy($score);

        return new BurnResult($momentum, $score, $total, $this->bandFor($bounds, $total), $roll->isMatch() ? SpecialResult::Match : null);
    }

    /**
     * The one rule for offering a burn (ADR 0049): the momentum, capped at the score cap, beats more
     * challenge dice than the score the roll had. A zero or negative momentum beats nothing.
     */
    public function burnImproves(ChallengeRoll $roll, int $score, int $momentum): bool
    {
        return $this->push instanceof CheckPush && $this->challenge instanceof CheckChallenge && null !== $this->challenge->momentum
            && $roll->beatenBy($this->burnScore($momentum)) > $roll->beatenBy($score);
    }

    private function burnScore(int $momentum): int
    {
        $max = $this->challenge?->scoreMax;

        return null === $max ? $momentum : min($momentum, $max);
    }

    /**
     * Whether a roll of this check can have the special result (ADR 0044): a critical needs critical
     * pairs, a bestial failure Hunger dice, a messy critical both. A dice check produces none, and a
     * literal Hunger count of 0 rolls no Hunger dice (a formula might).
     */
    public function canProduce(SpecialResult $special): bool
    {
        $criticals = $this->pool instanceof CheckPool && null !== $this->pool->criticals;
        $hunger = $this->pool instanceof CheckPool && null !== $this->pool->hunger && 0 !== $this->pool->hunger;

        return match ($special) {
            // A match is shown only: a step cannot name it, the band's outcome applies (ADR 0049).
            SpecialResult::Match => false,
            SpecialResult::Critical => $criticals,
            SpecialResult::MessyCritical => $criticals && $hunger,
            SpecialResult::BestialFailure => $hunger,
        };
    }

    /**
     * The special result of a roll (ADR 0044). A roll fails when its total is below the difficulty, if
     * the check declares one, else when its band is the first of a check with two bands or more; a
     * check with a single band never fails. A failed roll with a Hunger 1 is a bestial failure; one
     * that does not fail is a messy critical with a critical pair holding a Hunger die, else a
     * critical with a critical pair. The dice only state facts a pool declares, so a pool without
     * critical pairs or Hunger dice never has a special result.
     */
    private function specialOf(PoolRoll $roll, int $total, ?int $difficulty, CheckBand $band): ?SpecialResult
    {
        $failed = null !== $difficulty ? $total < $difficulty : \count($this->bands) >= 2 && $band === $this->bands[0];

        return match (true) {
            $failed => $roll->hasHungerOne() ? SpecialResult::BestialFailure : null,
            $roll->hasHungerCriticalPair() => SpecialResult::MessyCritical,
            $roll->hasCriticalPair() => SpecialResult::Critical,
            default => null,
        };
    }

    /**
     * The first band whose upper bound is at least the total. The last band has no bound and catches
     * the rest, so a band always matches.
     *
     * @param list<?int> $bounds the evaluated upper bounds, in band order
     */
    private function bandFor(array $bounds, int $total): CheckBand
    {
        return $this->bands[array_find_key($bounds, static fn (?int $bound): bool => null === $bound || $total <= $bound) ?? \count($this->bands) - 1];
    }

    /**
     * @throws FormulaEvaluationFailed
     */
    private function countOf(CheckPool $pool, CheckValues $values): int
    {
        return $pool->count instanceof Formula ? $pool->count->evaluate($values) : $pool->count;
    }

    /**
     * @throws FormulaEvaluationFailed
     */
    private function hungerOf(CheckPool $pool, CheckValues $values): int
    {
        return $pool->hunger instanceof Formula ? $pool->hunger->evaluate($values) : $pool->hunger ?? 0;
    }

    /**
     * @return list<ModifierTerm> what each reference of the formula reads, in the order it first names them
     */
    private function termsOf(int|Formula|null $formula, CheckValues $values): array
    {
        return array_map(static fn (Reference $reference): ModifierTerm => new ModifierTerm($reference, $values->value($reference)), $formula instanceof Formula ? $formula->references() : []);
    }

    private function effectiveDifficulty(?int $given): ?int
    {
        if (!$this->difficulty instanceof CheckDifficulty) {
            return null === $given ? null : throw CheckDifficultyNotDeclared::forCheck($this->key);
        }

        if (null === $given) {
            return $this->difficulty->default;
        }

        return $this->difficulty->allows($given) ? $given : throw CheckDifficultyOutOfRange::of($this->key, $given, $this->difficulty);
    }
}
