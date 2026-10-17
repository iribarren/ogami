<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\ValueResolver;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * A saved check as a test roll evaluates it, in the order of Play's Check::perform (ADR 0024, 0032):
 * the effective difficulty, the situational modifier, the modifier, the band bounds, the dice, the
 * overflow guard, the band. Studio cannot import Play, so it composes the same shared-kernel pieces
 * itself. The saved draft already passed the item rules, so nothing here enforces them again: bounds
 * that do not increase for the samples are returned as they are.
 */
final readonly class CheckTestRoll
{
    public const int MIN_SITUATIONAL = -100;
    public const int MAX_SITUATIONAL = 100;

    /**
     * @param non-empty-list<OutcomeBand> $bands in band order
     */
    public function __construct(
        public string $key,
        public DiceExpression $dice,
        public ?Formula $modifier,
        public ?CheckDifficultyRange $difficulty,
        public array $bands,
    ) {
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
        $modifier = $check['modifier'] ?? null;

        try {
            return new self(
                $key,
                DiceExpression::fromString(\is_string($dice) ? $dice : throw CheckTestRollFailed::unreadable($where.' has no dice notation')),
                null === $modifier ? null : Formula::fromString(\is_string($modifier) ? $modifier : throw CheckTestRollFailed::unreadable($where.' has a modifier that is not a string')),
                self::difficulty($check['difficulty'] ?? null, $where),
                self::bands($check['bands'] ?? null, $where),
            );
        } catch (InvalidDiceExpression|InvalidFormula $invalid) {
            throw CheckTestRollFailed::unreadable(\sprintf('%s has %s that does not parse: %s', $where, $invalid instanceof InvalidDiceExpression ? 'dice' : 'a formula', rtrim($invalid->getMessage(), '.')), $invalid);
        }
    }

    /**
     * Rolls the dice and picks the band. The modifier and every band bound are evaluated first, so a
     * check that cannot be evaluated rolls nothing.
     *
     * @param ?int $difficulty the Check difficulty chosen for the roll; omitted, the declared default applies
     *
     * @throws InvalidTestRoll     when a difficulty is given and the check declares none or it is outside the declared range, or the situational modifier is outside -100..100
     * @throws CheckTestRollFailed when a formula or the dice cannot be evaluated, or the total overflows
     */
    public function perform(RandomNumberGenerator $random, ValueResolver $sheet, ?int $difficulty, int $situational): CheckTestRollResult
    {
        $effective = $this->effectiveDifficulty($difficulty);
        if ($situational < self::MIN_SITUATIONAL || $situational > self::MAX_SITUATIONAL) {
            throw InvalidTestRoll::outside('situational', self::MIN_SITUATIONAL, self::MAX_SITUATIONAL, $situational);
        }

        try {
            $modifier = $this->modifier?->evaluate(new CheckFormulaValues($sheet, null)) ?? 0;
            $bandValues = new CheckFormulaValues($sheet, $effective);
            $bounds = array_map(static fn (OutcomeBand $band): ?int => $band->upTo instanceof Formula ? $band->upTo->evaluate($bandValues) : $band->upTo, $this->bands);
            $roll = $this->dice->roll($random);
        } catch (FormulaEvaluationFailed|InvalidDiceExpression $failed) {
            throw CheckTestRollFailed::evaluation($this->key, $failed);
        }

        // The dice total can be close to PHP_INT_MAX, so adding the modifiers can leave the integers (as in the dice kernel).
        if (abs((float) $roll->total() + $modifier + $situational) >= (float) \PHP_INT_MAX) {
            throw CheckTestRollFailed::evaluation($this->key, new \OverflowException('the total of the check overflows.'));
        }

        $total = $roll->total() + $modifier + $situational;
        // The last band has no bound and catches the rest, so a band always matches.
        $index = array_find_key($bounds, static fn (?int $bound): bool => null === $bound || $total <= $bound) ?? \count($this->bands) - 1;

        return new CheckTestRollResult($roll, $modifier, $situational, $effective, $total, $this->bands, $bounds, $this->bands[$index]);
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
