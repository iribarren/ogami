<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ValueResolver;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\PoolRoll;
use App\Randomness\Domain\PoolRollNotPushable;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * A Check of a GameSystem release (ADR 0024): a dice expression, an optional formula modifier over
 * the sheet, an optional Check difficulty the player sets at roll time, and Outcome bands. The total
 * is the dice, plus the modifier, plus the situational modifier; the first band whose upper bound
 * is at least the total is the outcome, and the last band catches the rest.
 *
 * A check rolls a dice expression or a pool, never both (ADR 0041). A pool check has no modifier:
 * the situational modifier adds or removes dice, and the total is the number of successes. Only a
 * pool check can declare a push, which re-rolls the dice that failed for a cost.
 */
final readonly class Check
{
    public const int MIN_SITUATIONAL = -100;
    public const int MAX_SITUATIONAL = 100;

    /**
     * @param list<CheckBand> $bands in band order, unique keys, only the last without an upper bound
     *
     * @throws \InvalidArgumentException when there is neither dice nor a pool or both, a pool check has a modifier, a check without a pool declares a push, there is no band,
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
    ) {
        if ($dice instanceof DiceExpression === $pool instanceof CheckPool) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls either a dice expression or a pool.', $key));
        }

        if ($pool instanceof CheckPool && $modifier instanceof Formula) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls a pool and has no modifier.', $key));
        }

        if ($push instanceof CheckPush && !$pool instanceof CheckPool) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" declares a push without rolling a pool.', $key));
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
            $roll = $this->dice?->roll($random);
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

        $roll ??= throw new \LogicException(\sprintf('Check "%s" rolls neither dice nor a pool.', $this->key));

        // The dice total can be close to PHP_INT_MAX, so adding the modifiers can leave the integers (as in the dice kernel).
        if (abs((float) $roll->total() + $modifier + $situational) >= (float) \PHP_INT_MAX) {
            throw CheckEvaluationFailed::of($this->key, new \OverflowException('the total of the check overflows.'));
        }

        $total = $roll->total() + $modifier + $situational;

        return new CheckResult($roll, $modifier, $situational, $effective, $total, $this->bandFor($bounds, $total), $terms);
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
     * Whether a roll of this check can have the special result (ADR 0044): a critical needs critical
     * pairs, a bestial failure Hunger dice, a messy critical both. A dice check produces none, and a
     * literal Hunger count of 0 rolls no Hunger dice (a formula might).
     */
    public function canProduce(SpecialResult $special): bool
    {
        $criticals = $this->pool instanceof CheckPool && null !== $this->pool->criticals;
        $hunger = $this->pool instanceof CheckPool && null !== $this->pool->hunger && 0 !== $this->pool->hunger;

        return match ($special) {
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
