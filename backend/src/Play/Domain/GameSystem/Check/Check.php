<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ValueResolver;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * A Check of a GameSystem release (ADR 0024): a dice expression, an optional formula modifier over
 * the sheet, an optional Check difficulty the player sets at roll time, and Outcome bands. The total
 * is the dice, plus the modifier, plus the situational modifier; the first band whose upper bound
 * is at least the total is the outcome, and the last band catches the rest.
 */
final readonly class Check
{
    public const int MIN_SITUATIONAL = -100;
    public const int MAX_SITUATIONAL = 100;

    /**
     * @param list<CheckBand> $bands in band order, unique keys, only the last without an upper bound
     *
     * @throws \InvalidArgumentException when there is no band, two bands share a key, a band but the last has no upper bound,
     *                                   the last has one, or literal upper bounds do not strictly increase
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint,
        public DiceExpression $dice,
        public ?Formula $modifier,
        public ?CheckDifficulty $difficulty,
        public array $bands,
    ) {
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
     * Rolls the dice and picks the band. The modifier and every band bound are evaluated first, so a
     * check that cannot be evaluated rolls nothing.
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
            $modifierValues = new CheckValues($sheet, null);
            $modifier = $this->modifier?->evaluate($modifierValues) ?? 0;
            $terms = array_map(static fn (Reference $reference): ModifierTerm => new ModifierTerm($reference, $modifierValues->value($reference)), $this->modifier?->references() ?? []);
            $bandValues = new CheckValues($sheet, $effective);
            $bounds = array_map(static fn (CheckBand $band): ?int => $band->upTo instanceof Formula ? $band->upTo->evaluate($bandValues) : $band->upTo, $this->bands);
            $roll = $this->dice->roll($random);
        } catch (FormulaEvaluationFailed|InvalidDiceExpression $failed) {
            throw CheckEvaluationFailed::of($this->key, $failed);
        }

        // The dice total can be close to PHP_INT_MAX, so adding the modifiers can leave the integers (as in the dice kernel).
        if (abs((float) $roll->total() + $modifier + $situational) >= (float) \PHP_INT_MAX) {
            throw CheckEvaluationFailed::of($this->key, new \OverflowException('the total of the check overflows.'));
        }

        $total = $roll->total() + $modifier + $situational;
        // The last band has no bound and catches the rest, so a band always matches.
        $index = array_find_key($bounds, static fn (?int $bound): bool => null === $bound || $total <= $bound) ?? \count($this->bands) - 1;

        return new CheckResult($roll, $modifier, $situational, $effective, $total, $this->bands[$index], $terms);
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
