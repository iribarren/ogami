<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * The result of rolling a dice pool: every die in roll order and how many succeeded. A push (ADR 0041)
 * re-rolls the dice that failed, once, and keeps the successes where they are.
 */
final readonly class PoolRoll
{
    private int $successes;

    /**
     * @param list<PoolDie> $dice in roll order
     */
    public function __construct(
        private DicePool $pool,
        private array $dice,
    ) {
        $this->successes = \count(array_filter($dice, static fn (PoolDie $die): bool => $die->isSuccess()));
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

    public function successes(): int
    {
        return $this->successes;
    }

    /**
     * How many dice did not succeed: the dice a push would re-roll.
     */
    public function failures(): int
    {
        return $this->size() - $this->successes;
    }

    /**
     * Rebuilds a roll from the faces it showed, e.g. a stored one.
     *
     * @param list<int> $faces in roll order, one per die of the pool
     *
     * @throws InvalidDicePool when there are not as many faces as dice, or a face is outside 1..sides
     */
    public static function ofFaces(DicePool $pool, array $faces): self
    {
        if (\count($faces) !== $pool->count) {
            throw InvalidDicePool::dice($pool->count, \count($faces));
        }

        $dice = [];
        foreach ($faces as $face) {
            if ($face < 1 || $face > $pool->sides) {
                throw InvalidDicePool::face($face, $pool->sides);
            }

            $dice[] = new PoolDie($face, $face >= $pool->successAt);
        }

        return new self($pool, $dice);
    }

    /**
     * Re-rolls every die that did not succeed, once: the successes keep their face and place, and
     * each die re-rolled says so. This roll is left as it is.
     *
     * @throws PoolRollNotPushable when every die succeeded
     */
    public function push(RandomNumberGenerator $random): self
    {
        if (0 === $this->failures()) {
            throw PoolRollNotPushable::everyDieSucceeded();
        }

        $dice = array_map(function (PoolDie $die) use ($random): PoolDie {
            if ($die->isSuccess()) {
                return $die;
            }

            return new PoolDie($face = $random->between(1, $this->pool->sides), $face >= $this->pool->successAt, true);
        }, $this->dice);

        return new self($this->pool, $dice);
    }
}
