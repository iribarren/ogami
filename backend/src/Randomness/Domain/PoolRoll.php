<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * The result of rolling a dice pool: every die in roll order and how many succeeded. A push (ADR 0041)
 * re-rolls the regular dice that failed, once, and keeps the successes and the Hunger dice where they are.
 *
 * ADR 0044: pairs of dice showing the top face add the pool's critical bonus to the total. This class
 * only states the facts about the dice (pairs, a Hunger die in a pair, a Hunger 1); whether the roll
 * counts as a special result also depends on the check failing, which Play decides.
 */
final readonly class PoolRoll
{
    private int $successes;

    private int $criticalPairs;

    private bool $hungerPair;

    private bool $hungerOne;

    /**
     * @param list<PoolDie> $dice in roll order
     */
    public function __construct(
        private DicePool $pool,
        private array $dice,
    ) {
        $this->successes = \count(array_filter($dice, static fn (PoolDie $die): bool => $die->isSuccess()));

        // Hunger dice pair first, then the regular ones; a die is in one pair at most (ADR 0044).
        $top = static fn (bool $hunger): int => \count(array_filter($dice, static fn (PoolDie $die): bool => $die->isHunger() === $hunger && $die->face() === $pool->sides));
        $hungerTop = $top(true);
        $mixedPair = 1 === $hungerTop % 2 && $top(false) > 0 ? 1 : 0;
        $this->criticalPairs = intdiv($hungerTop, 2) + $mixedPair + intdiv($top(false) - $mixedPair, 2);
        $this->hungerPair = $hungerTop >= 2 || 1 === $mixedPair;
        $this->hungerOne = [] !== array_filter($dice, static fn (PoolDie $die): bool => $die->isHunger() && 1 === $die->face());
    }

    public function pool(): DicePool
    {
        return $this->pool;
    }

    /**
     * @return list<PoolDie>
     */
    public function dice(): array
    {
        return $this->dice;
    }

    public function size(): int
    {
        return \count($this->dice);
    }

    /**
     * How many dice succeeded, before the bonus of critical pairs.
     */
    public function successes(): int
    {
        return $this->successes;
    }

    /**
     * The total the check reads: the successes plus the bonus of every critical pair.
     */
    public function total(): int
    {
        return $this->successes + $this->criticalPairs * $this->pool->criticalsBonus;
    }

    /**
     * How many pairs of dice show the top face, whatever the pool's bonus (0 bonus makes them worth nothing).
     */
    public function criticalPairs(): int
    {
        return $this->criticalPairs;
    }

    /**
     * Whether a critical pair counts: the pool has a critical bonus and a pair exists.
     */
    public function hasCriticalPair(): bool
    {
        return $this->pool->criticalsBonus > 0 && $this->criticalPairs > 0;
    }

    /**
     * Whether a critical pair holds a Hunger die (the pool has a critical bonus, as for hasCriticalPair()).
     */
    public function hasHungerCriticalPair(): bool
    {
        return $this->hasCriticalPair() && $this->hungerPair;
    }

    /**
     * Whether a Hunger die shows 1.
     */
    public function hasHungerOne(): bool
    {
        return $this->hungerOne;
    }

    /**
     * How many dice did not succeed, Hunger dice included.
     */
    public function failures(): int
    {
        return $this->size() - $this->successes;
    }

    /**
     * How many regular dice did not succeed: the dice a push can re-roll.
     */
    public function regularFailures(): int
    {
        return \count($this->failedRegularIndexes());
    }

    public function isPushable(): bool
    {
        return $this->regularFailures() > 0;
    }

    /**
     * Rebuilds a roll from the faces it showed, e.g. a stored one.
     *
     * @param array<int, int> $faces in roll order, one per die of the pool; the keys are ignored (stored data is not trusted to be a list)
     *
     * @throws InvalidDicePool when there are not as many faces as dice, or a face is outside 1..sides
     */
    public static function ofFaces(DicePool $pool, array $faces): self
    {
        if (\count($faces) !== $pool->count) {
            throw InvalidDicePool::dice($pool->count, \count($faces));
        }

        $dice = [];
        foreach (array_values($faces) as $index => $face) {
            if ($face < 1 || $face > $pool->sides) {
                throw InvalidDicePool::face($face, $pool->sides);
            }

            $dice[] = $pool->die($index, $face);
        }

        return new self($pool, $dice);
    }

    /**
     * Re-rolls the regular dice that did not succeed, once: the successes and the Hunger dice keep their
     * face and place, and each die re-rolled says so. Without a cap it re-rolls every failed regular
     * die; with one, the first $maxDice of them in roll order. The totals and pairs of the new roll are
     * computed from the dice as they stand. This roll is left as it is.
     *
     * @throws PoolRollNotPushable when no regular die failed
     * @throws InvalidDicePool     when the cap is under 1
     */
    public function push(RandomNumberGenerator $random, ?int $maxDice = null): self
    {
        if (null !== $maxDice && $maxDice < 1) {
            throw InvalidDicePool::maxDice($maxDice);
        }

        $failed = $this->failedRegularIndexes();
        if ([] === $failed) {
            throw PoolRollNotPushable::noRegularDieFailed();
        }

        $rerolled = array_flip(null === $maxDice ? $failed : \array_slice($failed, 0, $maxDice));
        $dice = [];
        foreach ($this->dice as $index => $die) {
            $dice[] = isset($rerolled[$index]) ? $this->pool->die($index, $random->between(1, $this->pool->sides), true) : $die;
        }

        return new self($this->pool, $dice);
    }

    /**
     * @return list<int> the positions, in roll order, of the regular dice that did not succeed
     */
    private function failedRegularIndexes(): array
    {
        return array_keys(array_filter($this->dice, static fn (PoolDie $die): bool => !$die->isSuccess() && !$die->isHunger()));
    }
}
