<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\ValueResolver;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\InvalidDicePool;
use App\Randomness\Domain\PoolRoll;
use App\Randomness\Domain\PoolRollNotPushable;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * A saved check as a test roll evaluates it, in the order of Play's Check::perform (ADR 0024, 0032):
 * the effective difficulty, the situational modifier, the modifier, the band bounds, the dice, the
 * overflow guard, the band. Studio cannot import Play, so it composes the same shared-kernel pieces
 * itself. A check rolls dice or a pool (ADR 0041), never both; a pool check may declare a push, which
 * the sandbox applies to the faces of an earlier roll. The saved draft already passed the item rules,
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
     * @throws \InvalidArgumentException when the check rolls both or neither of dice and a pool, a pool has a modifier, or a push has no pool
     */
    public function __construct(
        public string $key,
        public ?DiceExpression $dice,
        public ?Formula $modifier,
        public ?CheckDifficultyRange $difficulty,
        public array $bands,
        public ?CheckTestPool $pool = null,
        public ?CheckTestPush $push = null,
    ) {
        if ($dice instanceof DiceExpression === $pool instanceof CheckTestPool) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls dice or a pool, exactly one of them.', $key));
        }

        if ($pool instanceof CheckTestPool && $modifier instanceof Formula) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" rolls a pool, which takes no modifier.', $key));
        }

        if ($push instanceof CheckTestPush && !$pool instanceof CheckTestPool) {
            throw new \InvalidArgumentException(\sprintf('Check "%s" declares a push without a pool.', $key));
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
        if (null !== $dice && null !== $pool) {
            throw CheckTestRollFailed::unreadable($where.' has both dice and a pool');
        }

        if (null === $dice && null === $pool) {
            throw CheckTestRollFailed::unreadable($where.' has neither dice nor a pool');
        }

        if (null !== $pool && null !== $modifier) {
            throw CheckTestRollFailed::unreadable($where.' has a modifier with a pool');
        }

        if (null !== $push && null === $pool) {
            throw CheckTestRollFailed::unreadable($where.' has a push without a pool');
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
            );
        } catch (\InvalidArgumentException $invalid) {
            throw CheckTestRollFailed::unreadable(\sprintf('%s has a pool that breaks the contract: %s', $where, rtrim($invalid->getMessage(), '.')), $invalid);
        } catch (InvalidDiceExpression|InvalidFormula $invalid) {
            throw CheckTestRollFailed::unreadable(\sprintf('%s has %s that does not parse: %s', $where, $invalid instanceof InvalidDiceExpression ? 'dice' : 'a formula', rtrim($invalid->getMessage(), '.')), $invalid);
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
            $bounds = $this->boundsOf($sheet, $effective);
            $roll = $this->dice?->roll($random);
            $poolRoll = $this->pool?->pool($this->pool->sizeFor($count, $situational))->roll($random);
        } catch (FormulaEvaluationFailed|InvalidDiceExpression $failed) {
            throw CheckTestRollFailed::evaluation($this->key, $failed);
        }

        if ($this->pool instanceof CheckTestPool && $poolRoll instanceof PoolRoll) {
            $total = $poolRoll->successes();

            return new CheckTestRollResult(null, 0, $situational, $effective, $total, $this->bands, $bounds, $this->bandFor($bounds, $total), $this->poolRollOf($poolRoll, $count), $this->push);
        }

        $roll ?? throw new \LogicException(\sprintf('Check "%s" rolls neither dice nor a pool.', $this->key));

        // The dice total can be close to PHP_INT_MAX, so adding the modifiers can leave the integers (as in the dice kernel).
        if (abs((float) $roll->total() + $modifier + $situational) >= (float) \PHP_INT_MAX) {
            throw CheckTestRollFailed::evaluation($this->key, new \OverflowException('the total of the check overflows.'));
        }

        $total = $roll->total() + $modifier + $situational;

        return new CheckTestRollResult($roll, $modifier, $situational, $effective, $total, $this->bands, $bounds, $this->bandFor($bounds, $total));
    }

    /**
     * Pushes the roll a client holds (ADR 0041), statelessly: the pool is sized again for these samples,
     * rebuilt from the faces of the roll, and every die that failed is re-rolled once. The band is
     * picked again for the pushed successes. The cost is not applied: a test roll has no campaign.
     *
     * @param list<int> $faces the face of each die of the roll as it was shown, in roll order
     *
     * @throws CheckTestRollNotPushable when the check declares no push, or no die of the roll failed
     * @throws InvalidTestRoll          when the difficulty or the situational modifier is not accepted, or the faces are not the roll of this pool
     * @throws CheckTestRollFailed      when the pool count or a band bound cannot be evaluated; nothing is re-rolled
     */
    public function push(RandomNumberGenerator $random, ValueResolver $sheet, ?int $difficulty, int $situational, array $faces): CheckTestRollResult
    {
        if (!$this->pool instanceof CheckTestPool || !$this->push instanceof CheckTestPush) {
            throw CheckTestRollNotPushable::noPush($this->key);
        }

        $effective = $this->effectiveDifficulty($difficulty);
        $this->assertSituational($situational);

        try {
            $count = $this->countOf($sheet);
            $bounds = $this->boundsOf($sheet, $effective);
        } catch (FormulaEvaluationFailed $failed) {
            throw CheckTestRollFailed::evaluation($this->key, $failed);
        }

        try {
            $rolled = PoolRoll::ofFaces($this->pool->pool($this->pool->sizeFor($count, $situational)), $faces);
        } catch (InvalidDicePool $invalid) {
            throw InvalidTestRoll::at('faces', rtrim($invalid->getMessage(), '.'));
        }

        try {
            $pushed = $rolled->push($random);
        } catch (PoolRollNotPushable) {
            throw CheckTestRollNotPushable::noFailedDie($this->key);
        }

        return new CheckTestRollResult(null, 0, $situational, $effective, $pushed->successes(), $this->bands, $bounds, $this->bandFor($bounds, $pushed->successes()), $this->poolRollOf($pushed, $count), $this->push, $rolled->successes());
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
        return new CheckTestPoolRoll($roll, $count, $this->pool?->count instanceof Formula ? $this->pool->count->toString() : null);
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

        if (\is_array($count)) {
            $formula = $count['formula'] ?? null;
            $count = Formula::fromString(\is_string($formula) ? $formula : throw CheckTestRollFailed::unreadable($where.' has a pool count without a formula'));
        }

        return new CheckTestPool(\is_int($count) || $count instanceof Formula ? $count : throw CheckTestRollFailed::unreadable($where.' has a pool count that is neither an integer nor a formula'), $sides, $successAt);
    }

    private static function readPush(mixed $stored, string $where): CheckTestPush
    {
        $label = \is_array($stored) ? $stored['label'] ?? null : null;
        if (!\is_string($label) || '' === trim($label)) {
            throw CheckTestRollFailed::unreadable($where.' has a push without a label');
        }

        // The cost is never applied in a test roll, but a push that is not an object with a list of effects is not a push.
        $effects = $stored['effects'] ?? [];
        if (!\is_array($effects) || !array_is_list($effects)) {
            throw CheckTestRollFailed::unreadable($where.' has push effects that are not a list');
        }

        return new CheckTestPush($label);
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
